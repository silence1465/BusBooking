<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Many starts, one destination: each starting pickup point can leave at its own time. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trip_fares', function (Blueprint $table) {
            $table->time('departure_time')->nullable()->after('price'); // empty = the trip's own time
        });
    }

    public function down(): void
    {
        Schema::table('trip_fares', fn (Blueprint $table) => $table->dropColumn('departure_time'));
    }
};
