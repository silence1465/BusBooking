<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PickupPoint;
use App\Models\Seat;
use App\Models\SeatHold;
use App\Models\Setting;
use App\Models\Trip;
use App\Models\User;
use App\Support\BookingCode;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BookingService
{
    public function __construct(
        private readonly SeatHoldService $holds,
        private readonly PaymentService $payments,
        private readonly BoardingService $boarding,
    ) {}

    /**
     * Create a pending booking, hold the seats and start the Paystack transaction.
     *
     * @param  array{trip_id:int,pickup_point_id:int,seat_numbers:int[],passenger_name:string,phone:string,email?:?string}  $data
     * @return array{booking: Booking, payment: Payment, authorization_url: string}
     */
    public function create(array $data, ?User $user = null): array
    {
        [$booking, $payment] = DB::transaction(function () use ($data, $user) {
            // The trip row is the lock: bookings for the same trip are serialised here.
            $trip = Trip::whereKey($data['trip_id'])->lockForUpdate()->first();

            if (! $trip || ! $trip->loadMissing('route')->isOpenForSale()) {
                throw new BookingException('This trip is no longer available for booking.');
            }

            $pickup = PickupPoint::where('id', $data['pickup_point_id'])
                ->where('route_id', $trip->route_id)->where('status', 'active')->first();
            if (! $pickup) {
                throw new BookingException('Please choose a valid pickup point for this route.');
            }

            [$fare, $place] = $this->journey($trip, isset($data['destination_id']) ? (int) $data['destination_id'] : null, $pickup->id);

            $seats = $this->claimSeats($trip, $data, false);

            $expiresAt = now()->addMinutes((int) Setting::get('seat_hold_minutes', (string) config('busbooking.seat_hold_minutes')));

            $booking = new Booking([
                'user_id' => $user?->id,
                'trip_id' => $trip->id,
                'pickup_point_id' => $pickup->id,
                'passenger_name' => $data['passenger_name'],
                'phone' => Phone::normalize($data['phone']),
                'email' => $data['email'] ?? null,
                'amount' => $fare * $seats->count(),
            ]);
            $this->applyPlace($booking, $trip, $place);
            // Not mass assignable on purpose.
            $booking->code = BookingCode::generate();
            $booking->status = Booking::PENDING;
            $booking->expires_at = $expiresAt;
            // The online platform's share, per seat, at today's rate. Never more than the seat price.
            $booking->commission = $trip->commissionPerSeat($fare) * $seats->count();
            $booking->save();

            foreach ($seats as $seat) {
                $booking->seats()->create(['seat_id' => $seat->id, 'seat_number' => $seat->seat_number]);
            }
            $this->holds->hold($booking, $seats, SeatHold::HELD, $expiresAt);

            $payment = $booking->payments()->create([
                'reference' => 'BBK-'.strtoupper(Str::random(16)),
                'amount' => $booking->amount,
                'currency' => config('busbooking.currency'),
            ]);

            return [$booking, $payment];
        });

        try {
            $url = $this->payments->initialize($booking, $payment);
        } catch (Throwable $e) {
            report($e);
            $this->cancel($booking, 'payment_init_failed');
            throw new BookingException('We could not start the payment. Please try again.', 502);
        }

        $this->boarding->sync($booking->trip);

        return ['booking' => $booking->fresh(), 'payment' => $payment, 'authorization_url' => $url];
    }

    /**
     * A ticket sold over the counter. The seats go straight to BOOKED, so online sales see them
     * as taken immediately. The price is the trip's price: a station master cannot change it.
     *
     * @param  array{seat_numbers:int[],passenger_name?:?string,phone?:?string,pickup_point_id?:?int}  $data
     */
    public function sellAtStation(Trip $trip, array $data, User $seller): Booking
    {
        $booking = DB::transaction(function () use ($trip, $data, $seller) {
            $trip = Trip::whereKey($trip->id)->lockForUpdate()->first();

            if (! $trip || ! $trip->isOpenForCounterSale()) {
                throw new BookingException('Seats can only be sold on a trip that is open for sale. A scheduled trip stays open at the counter until its day ends.');
            }

            $pickupId = null;
            if (! empty($data['pickup_point_id'])) {
                $pickupId = PickupPoint::where('id', $data['pickup_point_id'])
                    ->where('route_id', $trip->route_id)->where('status', 'active')->value('id');
                if (! $pickupId) {
                    throw new BookingException('Please choose a valid pickup point for this route.');
                }
            }

            [$fare, $place] = $this->journey($trip, isset($data['destination_id']) ? (int) $data['destination_id'] : null, $pickupId, true);

            $seats = $this->claimSeats($trip, $data, true);

            $booking = new Booking([
                'user_id' => null,
                'trip_id' => $trip->id,
                'pickup_point_id' => $pickupId,
                'passenger_name' => trim((string) ($data['passenger_name'] ?? '')) ?: 'Sold at station',
                'phone' => ! empty($data['phone']) ? Phone::normalize($data['phone']) : null,
                'email' => null,
                'amount' => $fare * $seats->count(),
            ]);
            $this->applyPlace($booking, $trip, $place);
            $booking->code = BookingCode::generate();
            $booking->source = 'station';
            $booking->sold_by = $seller->id;
            $booking->status = Booking::CONFIRMED;
            $booking->save();

            foreach ($seats as $seat) {
                $booking->seats()->create(['seat_id' => $seat->id, 'seat_number' => $seat->seat_number]);
            }
            $this->holds->hold($booking, $seats, SeatHold::BOOKED, null);

            $payment = $booking->payments()->create([
                'reference' => 'STN-'.strtoupper(Str::random(14)),
                'amount' => $booking->amount,
                'currency' => config('busbooking.currency'),
            ]);
            $payment->status = Payment::PAID;
            $payment->channel = 'cash';
            $payment->paid_at = now();
            $payment->notes = 'Sold at station by '.$seller->name.'.';
            $payment->save();

            return $booking;
        });

        $this->boarding->sync($booking->trip);

        return $booking;
    }

    /** Records the priced place on the booking: its destination, or its starting place on a many-starts trip. */
    private function applyPlace(Booking $booking, Trip $trip, ?string $place): void
    {
        // On a many-starts trip the starting place IS the pickup point already on the booking.
        if (! $trip->pricedByOrigin()) {
            $booking->destination = $place;
        }
    }

    /**
     * The price per seat and the place (destination, or starting place) for this booking. A trip with one price sells that price and has no
     * destination. A trip with several destinations has a fixed start, so the destination alone sets the price,
     * wherever the passenger boards. The seat is held for the whole trip either way.
     *
     * @return array{0: float, 1: ?string}
     */
    private function journey(Trip $trip, ?int $destinationId, ?int $pickupId = null, bool $atStation = false): array
    {
        if (! $trip->hasFares()) {
            return [(float) $trip->price, null];
        }

        // Many starts, one destination: the passenger boards at one of the route's pickup points and that decides the price.
        if ($trip->pricedByOrigin()) {
            if (! $pickupId) {
                throw new BookingException('Choose where you board.');
            }
            $fare = $trip->fareForPickup($pickupId);
            if (! $fare) {
                throw new BookingException('Nothing is sold from that pickup point on this trip. Please choose another.');
            }
            if (! $atStation && $trip->departsAtFor($pickupId)?->isPast()) {
                throw new BookingException('The bus has already left from that pickup point. Please choose another.');
            }

            return [(float) $fare->price, null];
        }
        if (! $destinationId) {
            throw new BookingException($trip->pricedByOrigin() ? 'Choose where you are travelling from.' : 'Choose where you are travelling to.');
        }

        $fare = $trip->fareById($destinationId);
        if (! $fare) {
            throw new BookingException('That place is not sold on this trip. Please choose another.');
        }

        return [(float) $fare->price, $fare->destination];
    }

    /**
     * Decide which seats this booking gets (call inside the trip lock). Vehicles with seat selection use the numbers
     * the customer chose; open-seating vehicles (Express, Voxy) get the lowest free seats for the requested count.
     */
    private function claimSeats(Trip $trip, array $data, bool $atStation): \Illuminate\Support\Collection
    {
        $trip->loadMissing('bus.category');
        $this->holds->expire($trip->id);

        if (! $trip->bus->allowsSeatSelection()) {
            $count = (int) ($data['seat_count'] ?? count($data['seat_numbers'] ?? []));
            if ($count < 1) {
                throw new BookingException('Choose how many seats you need.');
            }
            $takenIds = SeatHold::where('trip_id', $trip->id)->pluck('seat_id');
            $free = Seat::where('bus_id', $trip->bus_id)->whereNotIn('id', $takenIds)->orderBy('seat_number')->limit($count)->get();
            if ($free->count() < $count) {
                $left = $free->count();
                throw new BookingException($left > 0 ? "Only {$left} seat".($left === 1 ? '' : 's').' left on this vehicle.' : 'This vehicle is full.', 409);
            }

            return $free;
        }

        $numbers = collect($data['seat_numbers'] ?? [])->map(fn ($n) => (int) $n)->unique()->values();
        if ($numbers->isEmpty()) {
            throw new BookingException('Please choose your seat.');
        }
        $seats = Seat::where('bus_id', $trip->bus_id)->whereIn('seat_number', $numbers)->get();
        if ($seats->count() !== $numbers->count()) {
            throw new BookingException('One or more selected seats do not exist on this bus.');
        }
        $taken = $this->holds->unavailableSeatIds($trip->id, $seats->pluck('id'));
        if ($taken->isNotEmpty()) {
            $list = $seats->whereIn('id', $taken)->pluck('seat_number')->sort()->implode(', ');
            throw new BookingException($atStation
                ? "Seat {$list} is already taken. Refresh the seat map and choose another."
                : "Seat {$list} is no longer available. Please choose another seat.", 409);
        }

        return $seats;
    }

    public function cancel(Booking $booking, string $reason = 'cancelled_by_admin'): Booking
    {
        $cancelled = DB::transaction(function () use ($booking, $reason) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [Booking::CANCELLED, Booking::COMPLETED], true)) {
                throw new BookingException('This booking can no longer be cancelled.', 409);
            }

            $locked->status = Booking::CANCELLED;
            $locked->cancelled_at = now();
            $locked->cancellation_reason = $reason;
            $locked->save();

            $this->holds->release($locked);
            // A paid payment stays "paid" until an admin records the refund.
            $locked->payments()->where('status', Payment::PENDING)->update(['status' => Payment::FAILED]);

            return $locked;
        });

        $this->boarding->sync($cancelled->trip);

        return $cancelled;
    }
}
