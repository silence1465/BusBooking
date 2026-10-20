<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Payment;
use App\Models\Seat;
use App\Models\SeatHold;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Fill and go" vehicles. Vehicles wait in a queue per route: the front one is BOARDING (loading at the main
 * station), the rest are QUEUED. When the front one is dispatched the next one moves up. A vehicle leaves when it
 * is full or when the station master dispatches it, picking up pickup-point passengers on the way, and pays the
 * station due for its category at departure.
 *
 * No-show rules:
 *  - at the main station, before departure: the ticket moves ONCE to the next vehicle (seat is resold);
 *  - at a pickup point, after departure: the ticket is forfeited and the seat stays empty.
 */
class BoardingService
{
    public function __construct(
        private readonly SeatHoldService $holds,
        private readonly SmsService $sms,
    ) {}

    // ---------------------------------------------------------------- the queue

    /** Add a vehicle to the end of the route's queue. It becomes the boarding vehicle if nothing is boarding. */
    public function openBoarding(BusRoute $route, Bus $bus): Trip
    {
        if (! $route->isFillAndGo() || $route->status !== 'active') {
            throw new BookingException('This route does not use fill-and-go vehicles.');
        }
        if (! $route->fare || (float) $route->fare <= 0) {
            throw new BookingException('An admin has not set a fare for this route yet.');
        }
        if (! $route->pickupPoints()->where('is_main_station', true)->where('status', 'active')->exists()) {
            throw new BookingException('An admin has not set the main station pickup point for this route yet.');
        }
        if ($bus->status !== 'active') {
            throw new BookingException('That bus is not active.');
        }

        return DB::transaction(function () use ($route, $bus) {
            BusRoute::whereKey($route->id)->lockForUpdate()->first(); // serialise queue changes on this route
            Trip::carryQueueOver();

            if (Trip::where('bus_id', $bus->id)->whereIn('status', Trip::QUEUE_STATUSES)->exists()) {
                throw new BookingException('That bus is already in the queue.', 409);
            }

            $hasFront = Trip::where('route_id', $route->id)->where('status', Trip::BOARDING)->exists();
            $position = (int) Trip::where('route_id', $route->id)->whereIn('status', Trip::QUEUE_STATUSES)->max('queue_position') + 1;

            $trip = new Trip([
                'bus_id' => $bus->id,
                'route_id' => $route->id,
                'travel_date' => today()->toDateString(),
                'departure_time' => null,
                'price' => $route->fare,
                'status' => $hasFront ? Trip::QUEUED : Trip::BOARDING,
            ]);
            $trip->opened_at = now();
            $trip->queue_position = $position;
            $trip->save();

            return $trip;
        });
    }

    /** Move a queued vehicle one place up or down the queue. */
    public function move(Trip $trip, string $direction): void
    {
        DB::transaction(function () use ($trip, $direction) {
            BusRoute::whereKey($trip->route_id)->lockForUpdate()->first();
            $trip = Trip::findOrFail($trip->id);

            if ($trip->status !== Trip::QUEUED) {
                throw new BookingException('Only a vehicle that is waiting in the queue can be moved.', 409);
            }

            $queued = Trip::where('route_id', $trip->route_id)->where('status', Trip::QUEUED)->orderBy('queue_position')->get();
            $i = $queued->search(fn (Trip $t) => $t->id === $trip->id);
            $neighbour = $queued[$direction === 'up' ? $i - 1 : $i + 1] ?? null;
            if (! $neighbour) {
                throw new BookingException($direction === 'up' ? 'It is already first in the queue.' : 'It is already last in the queue.', 409);
            }

            [$a, $b] = [$trip->queue_position, $neighbour->queue_position];
            $trip->queue_position = $b;
            $neighbour->queue_position = $a;
            $trip->save();
            $neighbour->save();
        });
    }

    /** Put a queued vehicle in front of the boarding one, as long as the boarding one has no passengers yet. */
    public function makeFront(Trip $trip): void
    {
        $promoted = DB::transaction(function () use ($trip) {
            BusRoute::whereKey($trip->route_id)->lockForUpdate()->first();
            $trip = Trip::findOrFail($trip->id);
            $front = Trip::where('route_id', $trip->route_id)->where('status', Trip::BOARDING)->first();

            if ($trip->status !== Trip::QUEUED || ! $front) {
                throw new BookingException('Only a queued vehicle can take the front place.', 409);
            }
            if (SeatHold::where('trip_id', $front->id)->exists()) {
                throw new BookingException('The vehicle at the front already has passengers, so it cannot be moved back.', 409);
            }

            [$a, $b] = [$front->queue_position, $trip->queue_position];
            $front->status = Trip::QUEUED;
            $front->queue_position = $b;
            $trip->status = Trip::BOARDING;
            $trip->queue_position = $a;
            $front->save();
            $trip->save();

            return $trip;
        });

        $this->announceBoarding($promoted);
    }

    /** Take a vehicle out of the queue (it broke down, driver went home...). Only while it has no passengers. */
    public function removeFromQueue(Trip $trip): void
    {
        $promoted = DB::transaction(function () use ($trip) {
            BusRoute::whereKey($trip->route_id)->lockForUpdate()->first();
            $trip = Trip::findOrFail($trip->id);

            if (! in_array($trip->status, Trip::QUEUE_STATUSES, true)) {
                throw new BookingException('That vehicle is not in the queue.', 409);
            }
            if (SeatHold::where('trip_id', $trip->id)->exists() || $trip->bookings()->whereIn('status', [Booking::PENDING, Booking::CONFIRMED])->exists()) {
                throw new BookingException('That vehicle already has passengers. Dispatch it, or move their tickets first.', 409);
            }

            $wasFront = $trip->status === Trip::BOARDING;
            $trip->status = Trip::CANCELLED;
            $trip->queue_position = null;
            $trip->save();

            return $wasFront ? $this->promoteNext($trip->route_id) : null;
        });

        $this->announceBoarding($promoted);
    }

    /** If nothing is boarding on the route, the next queued vehicle steps up. Call inside a transaction. */
    private function promoteNext(int $routeId): ?Trip
    {
        if (Trip::where('route_id', $routeId)->where('status', Trip::BOARDING)->exists()) {
            return null;
        }
        $next = Trip::where('route_id', $routeId)->where('status', Trip::QUEUED)->orderBy('queue_position')->lockForUpdate()->first();
        if (! $next) {
            return null;
        }
        $next->status = Trip::BOARDING;
        $next->save();

        return $next;
    }

    /** The vehicle has just reached the front: tell its main-station passengers it is loading now. */
    private function announceBoarding(?Trip $trip): void
    {
        if (! $trip) {
            return;
        }
        $trip->load('route', 'bus');
        $name = $this->mainStationName($trip);
        foreach ($this->unresolvedAtStation($trip) as $booking) {
            if ($booking->phone) {
                $this->sms->send($booking->phone, "Your bus is now boarding at {$name}. Please be at the station. Booking code {$booking->code}.", $booking->id, 'boarding');
            }
        }
        $this->sync($trip);
    }

    // ---------------------------------------------------------------- full / almost full

    public function availableSeats(Trip $trip): int
    {
        $trip->loadMissing('bus');
        $taken = SeatHold::where('trip_id', $trip->id)
            ->where(fn ($q) => $q->where('status', SeatHold::BOOKED)->orWhere('expires_at', '>', now()))
            ->count();

        return max(0, $trip->bus->seat_count - $taken);
    }

    /** Call after any change to a vehicle's seats. Records when it became full and sends the "almost full" text once. */
    public function sync(Trip $trip): void
    {
        $trip = Trip::with(['route', 'bus'])->find($trip->id);
        if (! $trip || $trip->status !== Trip::BOARDING) {
            return; // only the vehicle at the front is loading
        }

        $available = $this->availableSeats($trip);

        if ($available === 0 && ! $trip->full_at) {
            $trip->full_at = now();
        } elseif ($available > 0 && $trip->full_at) {
            $trip->full_at = null;
        }

        $notify = $available > 0
            && $available <= config('busbooking.boarding.almost_full_seats')
            && ! $trip->almost_full_notified_at;
        if ($notify) {
            $trip->almost_full_notified_at = now();
        }
        $trip->save();

        if ($notify) {
            $this->textMainStationPassengers($trip);
        }
    }

    /** Paid passengers boarding at the main station who have not checked in yet (the ones we wait for). */
    public function unresolvedAtStation(Trip $trip): Collection
    {
        return Booking::with(['pickupPoint', 'seats'])
            ->where('trip_id', $trip->id)
            ->where('status', Booking::CONFIRMED)
            ->whereNull('confirmed_at')
            ->where('source', 'online')
            ->whereHas('pickupPoint', fn ($q) => $q->where('is_main_station', true))
            ->get();
    }

    private function mainStationName(Trip $trip): string
    {
        return $trip->route->pickupPoints()->where('is_main_station', true)->value('name') ?? 'the station';
    }

    private function textMainStationPassengers(Trip $trip): void
    {
        $name = $this->mainStationName($trip);
        $minutes = config('busbooking.boarding.grace_minutes');

        foreach ($this->unresolvedAtStation($trip) as $booking) {
            if ($booking->phone) {
                $this->sms->send($booking->phone, "Your bus is almost full. Be at {$name} within {$minutes} minutes. Booking code {$booking->code}.", $booking->id, 'almost_full');
            }
        }
    }

    // ---------------------------------------------------------------- dispatch

    /**
     * Send the vehicle off and record the station due. Unchecked main-station passengers block a fill-and-go
     * vehicle unless $leaveBehind is true, in which case their tickets move to the next vehicle.
     * Timetabled trips can be dispatched too, so the day's departures and dues are complete.
     */
    public function dispatch(Trip $trip, bool $leaveBehind, ?User $by = null, bool $dueCollected = false): Trip
    {
        $moved = collect();
        $promoted = null;

        $trip = DB::transaction(function () use ($trip, $leaveBehind, $by, $dueCollected, &$moved, &$promoted) {
            $trip = Trip::with(['route', 'bus.category'])->whereKey($trip->id)->lockForUpdate()->first();
            $fillAndGo = (bool) $trip?->isFillAndGo();

            if (! $trip || ($fillAndGo ? $trip->status !== Trip::BOARDING : $trip->status !== Trip::SCHEDULED)) {
                throw new BookingException($fillAndGo ? 'Only the vehicle that is boarding can be dispatched.' : 'Only a scheduled trip can be dispatched.', 409);
            }

            if ($fillAndGo) {
                if (! SeatHold::where('trip_id', $trip->id)->where('status', SeatHold::BOOKED)->exists()) {
                    throw new BookingException('No seats have been sold on this vehicle yet.', 409);
                }

                $unresolved = $this->unresolvedAtStation($trip);
                if ($unresolved->isNotEmpty()) {
                    if (! $leaveBehind) {
                        throw new BookingException(
                            $unresolved->count().' passenger'.($unresolved->count() === 1 ? '' : 's').' booked to board at the station '.($unresolved->count() === 1 ? 'has' : 'have').' not checked in.',
                            409,
                            ['unresolved' => $unresolved->count()],
                        );
                    }
                    foreach ($unresolved as $booking) {
                        $moved->push($this->noShowAtStation($booking, null));
                    }
                }
            }

            // Unpaid holds on a vehicle that is leaving are released; a late payment is flagged for refund.
            Booking::where('trip_id', $trip->id)->where('status', Booking::PENDING)->get()->each(function (Booking $b) {
                $b->forceFill(['status' => Booking::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'vehicle_departed'])->save();
                $b->payments()->where('status', Payment::PENDING)->update(['status' => Payment::FAILED]);
                $this->holds->release($b);
            });

            $trip->status = Trip::DEPARTED;
            $trip->departed_at = now();
            $trip->queue_position = null;
            // The due is fixed now: later rate changes do not rewrite history.
            $trip->station_due = $trip->bus->stationDue();
            if ($dueCollected && (float) $trip->station_due > 0) {
                $trip->due_paid_at = now();
                $trip->due_collected_by = $by?->id;
            }
            $trip->save();

            if ($fillAndGo) {
                $promoted = $this->promoteNext($trip->route_id);
            }

            return $trip;
        });

        foreach ($moved as $result) {
            $this->textMoved($result);
        }
        if ($trip->isFillAndGo()) {
            $this->textPickupPassengers($trip);
        }
        $this->announceBoarding($promoted);

        return $trip;
    }

    /** Record that the station due for a departed vehicle was (or was not) collected. */
    public function setDuePaid(Trip $trip, bool $paid, User $by): Trip
    {
        if (! in_array($trip->status, [Trip::DEPARTED, Trip::COMPLETED], true) || (float) $trip->station_due <= 0) {
            throw new BookingException('There is no station due to record for this vehicle.', 409);
        }

        $trip->due_paid_at = $paid ? now() : null;
        $trip->due_collected_by = $paid ? $by->id : null;
        $trip->save();

        return $trip;
    }

    private function textPickupPassengers(Trip $trip): void
    {
        $trip->loadMissing('route');
        $name = $this->mainStationName($trip);

        Booking::with('pickupPoint')
            ->where('trip_id', $trip->id)->where('status', Booking::CONFIRMED)->whereNull('confirmed_at')
            ->whereHas('pickupPoint', fn ($q) => $q->where('is_main_station', false))
            ->get()
            ->each(function (Booking $b) use ($name) {
                if ($b->phone) {
                    $this->sms->send($b->phone, "Your bus has left {$name}. Be ready at {$b->pickupPoint->name} now. Booking code {$b->code}.", $b->id, 'pickup');
                }
            });
    }

    // ---------------------------------------------------------------- no-show

    /** @return array{outcome:string,booking:Booking} outcome is "moved" or "forfeited" */
    public function markNoShow(Booking $booking, User $by): array
    {
        $result = DB::transaction(function () use ($booking, $by) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $trip = Trip::with('route')->findOrFail($booking->trip_id);

            if ($booking->status !== Booking::CONFIRMED || $booking->confirmed_at) {
                throw new BookingException('Only a paid passenger who has not checked in can be marked as a no-show.', 409);
            }
            if (! $trip->isFillAndGo()) {
                throw new BookingException('No-shows are only handled on fill-and-go vehicles.', 409);
            }
            if ($booking->source === 'station' || ! $booking->pickup_point_id) {
                throw new BookingException('Tickets sold at the station do not need a no-show.', 409);
            }

            $atStation = (bool) $booking->pickupPoint?->is_main_station;

            if ($atStation) {
                if ($trip->status !== Trip::BOARDING) {
                    throw new BookingException($trip->status === Trip::QUEUED ? 'This vehicle is still waiting in the queue.' : 'The vehicle has already left the station.', 409);
                }

                return $this->noShowAtStation($booking, $by);
            }

            if ($trip->status !== Trip::DEPARTED) {
                throw new BookingException('The vehicle has not left the station yet.', 409);
            }

            return $this->forfeit($booking, $by, $trip);
        });

        if ($result['outcome'] === 'moved') {
            $this->textMoved($result);
        }
        $this->sync(Trip::find($booking->trip_id));
        if ($result['booking']->trip_id !== $booking->trip_id) {
            $this->sync(Trip::find($result['booking']->trip_id));
        }

        return $result;
    }

    /** Main-station no-show: move once to the next vehicle; a second miss forfeits the ticket. */
    private function noShowAtStation(Booking $booking, ?User $by): array
    {
        if ($booking->moved_from_trip_id) {
            return $this->forfeit($booking, $by, Trip::findOrFail($booking->trip_id));
        }

        return ['outcome' => 'moved', 'booking' => $this->transferToNextVehicle($booking)];
    }

    private function forfeit(Booking $booking, ?User $by, Trip $trip): array
    {
        $booking->status = Booking::NO_SHOW;
        $booking->no_show_at = now();
        $booking->no_show_by = $by?->id;
        $booking->save();

        // Before departure the seat goes back on sale; after departure the vehicle runs with it empty.
        if (in_array($trip->status, Trip::QUEUE_STATUSES, true)) {
            $this->holds->release($booking);
        }

        return ['outcome' => 'forfeited', 'booking' => $booking];
    }

    private function transferToNextVehicle(Booking $booking): Booking
    {
        $old = Trip::whereKey($booking->trip_id)->lockForUpdate()->firstOrFail();
        $need = $booking->seats()->count();
        $wanted = $booking->seats()->pluck('seat_number')->all();

        // The next vehicle in the queue, then any other open vehicle on the route.
        $candidates = Trip::where('route_id', $old->route_id)->whereIn('status', Trip::QUEUE_STATUSES)->where('id', '!=', $old->id)
            ->orderByRaw('case when queue_position > ? then 0 else 1 end', [(int) $old->queue_position])->orderBy('queue_position')->lockForUpdate()->get();

        foreach ($candidates as $next) {
            $takenIds = SeatHold::where('trip_id', $next->id)
                ->where(fn ($q) => $q->where('status', SeatHold::BOOKED)->orWhere('expires_at', '>', now()))
                ->pluck('seat_id');
            $free = Seat::where('bus_id', $next->bus_id)->whereNotIn('id', $takenIds)->orderBy('seat_number')->get()->keyBy('seat_number');
            if ($free->count() < $need) {
                continue;
            }

            // Keep the same seat numbers when they are free, otherwise the lowest free ones.
            $chosen = collect();
            foreach ($wanted as $number) {
                if ($free->has($number)) {
                    $chosen->push($free->pull($number));
                }
            }
            while ($chosen->count() < $need) {
                $chosen->push($free->shift());
            }

            $this->holds->release($booking);
            $booking->seats()->get()->values()->each(function ($row, $i) use ($chosen) {
                $row->update(['seat_id' => $chosen[$i]->id, 'seat_number' => $chosen[$i]->seat_number]);
            });
            $booking->moved_from_trip_id = $old->id;
            $booking->moved_at = now();
            $booking->trip_id = $next->id;
            $booking->save();
            $this->holds->hold($booking, $chosen, SeatHold::BOOKED, null);

            return $booking->load('seats');
        }

        throw new BookingException('There is no next vehicle with free seats. Open the next vehicle first so the ticket can move.', 409);
    }

    private function textMoved(array $result): void
    {
        $booking = $result['booking'];
        if ($result['outcome'] !== 'moved' || ! $booking->phone) {
            return;
        }
        $seats = $booking->seats()->pluck('seat_number')->implode(', ');
        $this->sms->send($booking->phone, "You missed your bus. Your ticket {$booking->code} has moved to the next vehicle, seat {$seats}. Be at the station when it fills up.", $booking->id, 'missed');
    }
}
