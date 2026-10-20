<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A driver who regularly drives one bus is linked to it, so their phone opens straight to its current trip.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('bus_id')->nullable()->after('is_active')->constrained('buses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bus_id');
        });
    }
};
