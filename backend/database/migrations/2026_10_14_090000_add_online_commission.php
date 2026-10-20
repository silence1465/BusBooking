<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online commission: the part of each seat sold online that goes to the online platform, not the station.
 * Set per route, with an optional different amount per vehicle type on that route. The amount is copied onto
 * the booking when it is made, so changing a rate later never rewrites past sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->decimal('commission', 8, 2)->default(0)->after('fare'); // per seat
        });

        Schema::create('route_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('vehicle_category_id')->constrained('vehicle_categories')->cascadeOnDelete();
            $table->decimal('commission', 8, 2); // per seat, replaces the route's amount for this vehicle type
            $table->timestamps();
            $table->unique(['route_id', 'vehicle_category_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('commission', 10, 2)->default(0)->after('amount'); // total for the booking
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('commission'));
        Schema::dropIfExists('route_commissions');
        Schema::table('routes', fn (Blueprint $table) => $table->dropColumn('commission'));
    }
};
