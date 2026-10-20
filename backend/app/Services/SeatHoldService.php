<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\SeatHold;
use Illuminate\Support\Facades\DB;

/**
 * Owns the seat_holds table. A seat is unavailable while a row exists for (trip, seat):
 * status "held" until expires_at, status "booked" permanently (until the booking is cancelled).
 * Callers that insert holds must do so inside a transaction holding a lock on the trip row.
 */
class SeatHoldService
{
    /** Cancel every pending booking whose hold has lapsed and free its seats. */
    public function expire(?int $tripId = null): int
    {
        $query = SeatHold::query()->where('status', SeatHold::HELD)->where('expires_at', '<=', now());
        if ($tripId) {
            $query->where('trip_id', $tripId);
        }
        $bookingIds = $query->pluck('booking_id')->unique()->values();
        if ($bookingIds->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($bookingIds) {
            Booking::whereIn('id', $bookingIds)->where('status', Booking::PENDING)->update([
                'status' => Booking::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'expired',
            ]);
            Payment::whereIn('booking_id', $bookingIds)->where('status', Payment::PENDING)->update([
                'status' => Payment::FAILED,
                'notes' => 'Seat hold expired before payment was confirmed.',
            ]);
            SeatHold::whereIn('booking_id', $bookingIds)->where('status', SeatHold::HELD)->delete();
        });

        return $bookingIds->count();
    }

    /** @return \Illuminate\Support\Collection<int,int> seat ids that are currently unavailable */
    public function unavailableSeatIds(int $tripId, iterable $seatIds)
    {
        return SeatHold::where('trip_id', $tripId)->whereIn('seat_id', $seatIds)->pluck('seat_id');
    }

    public function hold(Booking $booking, iterable $seats, string $status, $expiresAt): void
    {
        foreach ($seats as $seat) {
            SeatHold::create([
                'trip_id' => $booking->trip_id,
                'seat_id' => $seat->id,
                'booking_id' => $booking->id,
                'status' => $status,
                'expires_at' => $expiresAt,
            ]);
        }
    }

    public function markBooked(Booking $booking): void
    {
        SeatHold::where('booking_id', $booking->id)->update(['status' => SeatHold::BOOKED, 'expires_at' => null]);
    }

    public function release(Booking $booking): void
    {
        SeatHold::where('booking_id', $booking->id)->delete();
    }
}
