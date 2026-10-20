<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a station master may create, switched on one by one by an admin. Off by default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_manage_buses')->default(false);
            $table->boolean('can_manage_pickup_points')->default(false);
            $table->boolean('can_manage_trips')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['can_manage_buses', 'can_manage_pickup_points', 'can_manage_trips']));
    }
};
