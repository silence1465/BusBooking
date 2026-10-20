<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip with several priced places goes one of two ways:
 *   destination: one starting point, several destinations (Accra to Kintampo, also Techiman), or
 *   origin:      several starting points, one destination (Techiman, Kintampo or Nsawam, all to Accra).
 * trip_fares.destination holds the place's name either way: the destination, or the starting place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->string('fares_by', 12)->default('destination')->after('price');
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('journey_from', 100)->nullable()->after('destination'); // the starting place, on an "origin" trip
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('journey_from'));
        Schema::table('trips', fn (Blueprint $table) => $table->dropColumn('fares_by'));
    }
};
