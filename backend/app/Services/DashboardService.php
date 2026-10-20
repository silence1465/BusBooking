<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\Payment;
use App\Models\Trip;

class DashboardService
{
    public function stats(): array
    {
        // The online commission: the part of each paid online booking that goes to the online platform. Counter sales
        // carry none, and refunded payments are not counted.
        $paidOnline = fn () => Payment::query()
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->where('bookings.source', 'online')->where('payments.status', Payment::PAID)->whereNull('payments.deleted_at');

        $daily = $paidOnline()
            ->where('payments.paid_at', '>=', today()->subDays(13)->startOfDay())
            ->selectRaw('DATE(payments.paid_at) as day, SUM(bookings.commission) as commission, COUNT(*) as payments')
            ->groupBy('day')->get()->keyBy('day');

        $series = collect(range(13, 0))->map(function ($i) use ($daily) {
            $day = today()->subDays($i)->toDateString();

            return [
                'date' => $day,
                'commission' => (float) ($daily[$day]->commission ?? 0),
                'payments' => (int) ($daily[$day]->payments ?? 0),
            ];
        })->values();

        return [
            'todays_bookings' => Booking::whereDate('created_at', today())
                ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED])->count(),
            'todays_commission' => (float) $paidOnline()->whereDate('payments.paid_at', today())->sum('bookings.commission'),
            'upcoming_trips' => Trip::whereIn('status', [Trip::SCHEDULED, ...Trip::QUEUE_STATUSES])->whereDate('travel_date', '>=', today())->count(),
            'total_passengers' => BookingSeat::whereHas('booking', fn ($q) => $q->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED]))->count(),
            'pending_payments' => Booking::where('status', Booking::PENDING)->where('expires_at', '>', now())->count(),
            'currency' => config('busbooking.currency'),
            'daily_commission' => $series,
        ];
    }
}
