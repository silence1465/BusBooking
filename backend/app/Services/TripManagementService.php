<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\PickupPoint;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

class TripManagementService
{
    public function create(array $data): Trip
    {
        if (\App\Models\BusRoute::find($data['route_id'])?->isFillAndGo()) {
            throw new BookingException('This route has no timetable. Station masters open vehicles for boarding from the station console.');
        }

        $data['departure_time'] = substr($data['departure_time'], 0, 5).':00';
        $this->assertBusFree($data['bus_id'], $data['travel_date'], $data['departure_time']);

        $fares = $data['fares'] ?? null;
        unset($data['fares']);

        return DB::transaction(function () use ($data, $fares) {
            $trip = Trip::create($data + ['status' => Trip::SCHEDULED]);
            $this->syncFares($trip, $fares ?? []);

            return $trip;
        });
    }

    public function update(Trip $trip, array $data): Trip
    {
        return DB::transaction(function () use ($trip, $data) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->first();

            $active = $trip->bookings()->whereIn('status', [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED]);
            $hasBookings = $active->exists();

            $changed = (isset($data['bus_id']) && (int) $data['bus_id'] !== (int) $trip->bus_id)
                || (isset($data['route_id']) && (int) $data['route_id'] !== (int) $trip->route_id)
                || (isset($data['travel_date']) && $data['travel_date'] !== $trip->travel_date->format('Y-m-d'))
                || (isset($data['departure_time']) && substr($data['departure_time'], 0, 5) !== substr($trip->departure_time, 0, 5));
            
            if ($hasBookings && $changed) {
                throw new BookingException('Bus, route, date and time cannot be changed once the trip has bookings.', 409);
            }

            if (($data['status'] ?? null) === Trip::CANCELLED
                && $trip->bookings()->where('status', Booking::CONFIRMED)->exists()) {
                throw new BookingException('Cancel the confirmed bookings on this trip before cancelling the trip.', 409);
            }

            if ($changed) {
                $this->assertBusFree($data['bus_id'] ?? $trip->bus_id, $data['travel_date'] ?? $trip->travel_date->format('Y-m-d'), $data['departure_time'] ?? $trip->departure_time, $trip->id);
            }

            if (isset($data['departure_time'])) {
                $data['departure_time'] = substr($data['departure_time'], 0, 5).':00';
            }
            $fares = $data['fares'] ?? null;
            unset($data['fares']);
            $trip->update($data);
            if ($fares !== null) {
                $this->syncFares($trip, $fares); // only affects bookings made from now on
            }

            if ($trip->status === Trip::COMPLETED) {
                $trip->bookings()->where('status', Booking::CONFIRMED)->update(['status' => Booking::COMPLETED]);
            }

            return $trip;
        });
    }

    /**
     * Replace a trip's priced places. One start, many destinations: each destination has one price whatever pickup
     * point the passenger boards at. Many starts, one destination: each priced place is one of the route's pickup
     * points, and boarding there sets the price.
     *
     * @param  array<int, array{place?:?string, pickup_point_id?:?int, price:numeric}>  $fares
     */
    private function syncFares(Trip $trip, array $fares): void
    {
        $pickups = $trip->pricedByOrigin() ? PickupPoint::where('route_id', $trip->route_id)->where('status', 'active')->pluck('name', 'id') : collect();

        // Each entry becomes [pickup_point_id|null, place name, price].
        $rows = [];
        $seen = [];
        foreach ($fares as $f) {
            if ($trip->pricedByOrigin()) {
                // many starts: priced per pickup point of this route; the place is that pickup point's name
                $pickupId = (int) ($f['pickup_point_id'] ?? 0);
                if (! $pickups->has($pickupId)) {
                    throw new BookingException('Starting places must be pickup points of this trip’s route.');
                }
                $place = $pickups[$pickupId];
            } else {
                $pickupId = null;
                $place = trim((string) ($f['place'] ?? ''));
                if ($place === '') {
                    throw new BookingException('Every place needs a name.');
                }
            }

            $key = $pickupId ? 'p'.$pickupId : mb_strtolower($place);
            if (isset($seen[$key])) {
                throw new BookingException('“'.$place.'” is listed twice.');
            }
            $seen[$key] = true;
            $time = $pickupId && ! empty($f['departure_time']) ? substr($f['departure_time'], 0, 5).':00' : null;
            $rows[] = [$pickupId ?: null, $place, $f['price'], $time];
        }

        $trip->fares()->delete();
        foreach ($rows as $i => [$pickupId, $place, $price, $time]) {
            $trip->fares()->create(['pickup_point_id' => $pickupId, 'destination' => $place, 'price' => $price, 'departure_time' => $time, 'sort_order' => $i + 1]);
        }
        $trip->unsetRelation('fares');
    }

    public function delete(Trip $trip): void
    {
        if ($trip->bookings()->whereIn('status', [Booking::PENDING, Booking::CONFIRMED, Booking::COMPLETED])->exists()) {
            throw new BookingException('This trip has bookings. Cancel it instead of deleting.', 409);
        }
        $trip->delete();
    }

    private function assertBusFree(int $busId, string $date, string $time, ?int $ignoreTripId = null): void
    {
        $clash = Trip::where('bus_id', $busId)->whereDate('travel_date', $date)
            ->where('departure_time', strlen($time) === 5 ? $time.':00' : $time)
            ->where('status', '!=', Trip::CANCELLED)
            ->when($ignoreTripId, fn ($q) => $q->where('id', '!=', $ignoreTripId))->exists();

        if ($clash) {
            throw new BookingException('This bus already has a trip at that date and time.');
        }
    }
}

