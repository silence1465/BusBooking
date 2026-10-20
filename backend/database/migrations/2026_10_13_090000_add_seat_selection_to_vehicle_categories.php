<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Some vehicle types (e.g. Express, Voxy) have open seating: passengers book a number of seats, not seat numbers.
    public function up(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->boolean('seat_selection')->default(true)->after('station_due');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_categories', function (Blueprint $table) {
            $table->dropColumn('seat_selection');
        });
    }
};
