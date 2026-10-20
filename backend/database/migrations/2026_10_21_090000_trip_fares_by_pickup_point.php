<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Many starts, one destination": the starting places are the route's own pickup points, so each priced place
 * points at the pickup point the passenger boards at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_fares', function (Blueprint $table) {
            $table->foreignId('pickup_point_id')->nullable()->after('trip_id')->constrained('pickup_points')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trip_fares', fn (Blueprint $table) => $table->dropConstrainedForeignId('pickup_point_id'));
    }
};
