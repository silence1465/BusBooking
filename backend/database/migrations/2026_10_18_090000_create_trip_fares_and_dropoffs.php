<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One trip, several destinations, a price for each journey. A seat is still held for the whole trip,
 * so the same seat numbers are shared by every journey on the trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_stop_id')->constrained('pickup_points')->cascadeOnDelete(); // where the passenger boards
            $table->foreignId('to_stop_id')->constrained('pickup_points')->cascadeOnDelete();   // where they get off
            $table->decimal('price', 10, 2);
            $table->timestamps();
            $table->unique(['trip_id', 'from_stop_id', 'to_stop_id']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('dropoff_point_id')->nullable()->after('pickup_point_id')->constrained('pickup_points')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('dropoff_point_id'));
        Schema::dropIfExists('trip_fares');
    }
};
