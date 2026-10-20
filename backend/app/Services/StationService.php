<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Booking;
use App\Models\Trip;
use App\Models\User;
use App\Support\BookingCode;
use Illuminate\Support\Facades\DB;

class StationService
{
    private const MESSAGES = [
        'valid' => 'Booking verified.',
        'invalid' => 'Booking code not found. Check the code and try again.',
        'cancelled' => 'This booking is no longer valid.',
        'unpaid' => 'Payment has not been confirmed for this booking.',
        'already_confirmed' => 'This passenger has already been confirmed.',
        'completed' => 'This trip is already completed.',
        'no_show' => 'This passenger did not board at the pickup point. The ticket was forfeited.',
        'not_assigned' => 'This ticket is not for a route you handle.',
        'wrong_trip' => 'This ticket is for a different trip than the one you are checking in.',
    ];

    private const WITH = ['trip.route', 'trip.bus', 'pickupPoint', 'seats', 'payment', 'confirmedBy'];

    /**
     * @param  ?int  $tripId  the trip the station master is currently boarding (optional)
     * @return array{status:string,message:string,booking:?Booking,warning:?string,ticket_trip:?array}
     */
    public function verify(string $input, User $by, ?int $tripId = null): array
    {
        $booking = Booking::with(self::WITH)->where('code', BookingCode::normalize($input))->first();

        return $this->evaluate($booking, $by, $tripId);
    }

    public function confirmArrival(string $input, User $by, ?int $tripId = null): Booking
    {
        return DB::transaction(function () use ($input, $by, $tripId) {
            $booking = Booking::where('code', BookingCode::normalize($input))->lockForUpdate()->first();
            $booking?->load(['payment']);
            $state = $this->evaluate($booking, $by, $tripId);

            if ($state['status'] !== 'valid') {
                throw new BookingException($state['message'], 409);
            }

            $booking->confirmed_at = now();
            $booking->confirmed_by = $by->id;
            $booking->save();

            return $booking->load(self::WITH);
        });
    }

    private function evaluate(?Booking $booking, User $by, ?int $tripId): array
    {
        if (! $booking) {
            return $this->result('invalid', null);
        }

        $trip = $booking->trip;

        // A route this user does not handle: no passenger details, but say where the ticket IS for
        // so the passenger can be sent to the right place.
        if (! $by->canWorkOnTrip($trip)) {
            return $this->result('not_assigned', null, null, $this->tripSummary($trip, $this->ticketState($booking)));
        }

        $status = $this->ticketState($booking);

        // Valid ticket, but not for the trip being boarded right now.
        if ($status === 'valid' && $tripId !== null && $tripId !== $trip->id) {
            return $this->result('wrong_trip', $booking, null, $this->tripSummary($trip, 'valid'));
        }

        $warning = null;
        if ($status === 'valid' && ! $trip->travel_date->isToday() && ! in_array($trip->status, \App\Models\Trip::QUEUE_STATUSES, true)) {
            $warning = 'This booking is for '.$trip->travel_date->format('j M Y').', not today.';
        }

        return $this->result($status, $booking, $warning);
    }

    /** cancelled | already_confirmed | unpaid | completed | valid, ignoring who is asking. */
    private function ticketState(Booking $booking): string
    {
        return match (true) {
            $booking->status === Booking::NO_SHOW => 'no_show',
            $booking->status === Booking::CANCELLED || $booking->trip->status === Trip::CANCELLED => 'cancelled',
            $booking->confirmed_at !== null => 'already_confirmed',
            $booking->status === Booking::PENDING || ! $booking->isPaid() => 'unpaid',
            $booking->status === Booking::COMPLETED => 'completed',
            default => 'valid',
        };
    }

    private function tripSummary(Trip $trip, string $ticketState): array
    {
        $trip->loadMissing('route');

        return [
            'origin' => $trip->route->origin,
            'destination' => $trip->route->destination,
            'travel_date' => $trip->travel_date->format('Y-m-d'),
            'departure_time_label' => $trip->timeLabel(),
            'ticket_state' => $ticketState,
        ];
    }

    private function result(string $status, ?Booking $booking, ?string $warning = null, ?array $ticketTrip = null): array
    {
        return [
            'status' => $status,
            'message' => self::MESSAGES[$status],
            'booking' => $booking,
            'warning' => $warning,
            'ticket_trip' => $ticketTrip,
        ];
    }
}
