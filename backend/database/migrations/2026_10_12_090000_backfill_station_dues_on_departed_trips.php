<?php

use App\Models\Trip;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Vehicles that departed before station dues existed get today's rate recorded (as outstanding),
    // so the daily record has no gaps.
    public function up(): void
    {
        Trip::with('bus.category')->whereIn('status', [Trip::DEPARTED, Trip::COMPLETED])->whereNull('station_due')
            ->get()->each(function (Trip $trip) {
                $trip->station_due = $trip->bus?->stationDue() ?? 0;
                $trip->saveQuietly();
            });
    }

    public function down(): void
    {
        // Nothing to undo: the recorded amounts are harmless.
    }
};
