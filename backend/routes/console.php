<?php

use App\Services\SeatHoldService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bookings:expire-holds', function (SeatHoldService $holds) {
    $this->info('Expired '.$holds->expire().' booking(s).');
})->purpose('Release seats held by unpaid bookings past their expiry');

Artisan::command('trips:complete-departed', function () {
    $done = 0;
    \App\Models\Trip::where('status', \App\Models\Trip::DEPARTED)->whereNull('departure_time')
        ->where('departed_at', '<', now()->subHours(12))->get()->each(function ($trip) use (&$done) {
            $trip->update(['status' => \App\Models\Trip::COMPLETED]);
            $trip->bookings()->where('status', \App\Models\Booking::CONFIRMED)->update(['status' => \App\Models\Booking::COMPLETED]);
            $done++;
        });
    $this->info("Completed {$done} vehicle(s).");
})->purpose('Mark fill-and-go vehicles that left more than 12 hours ago as completed');

Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('trips:complete-departed')->hourly();
Schedule::call(fn () => \App\Models\Trip::carryQueueOver())->dailyAt('00:01')->name('trips:carry-queue-over');
