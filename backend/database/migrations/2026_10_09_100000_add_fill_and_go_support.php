<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Fill and go" routes: no timetable. A vehicle is opened for boarding at the main station,
     * sells seats until it is full (or is dispatched early) and then leaves, picking up the
     * passengers who board at later pickup points on the way.
     */
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table) {
            $table->string('mode', 12)->default('scheduled')->after('status'); // scheduled | fill_and_go
            $table->decimal('fare', 10, 2)->nullable()->after('mode');          // price per seat for fill_and_go
        });

        Schema::table('pickup_points', function (Blueprint $table) {
            $table->unsignedSmallInteger('stop_order')->default(1)->after('description');
            $table->boolean('is_main_station')->default(false)->after('stop_order'); // boards at the station itself
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->time('departure_time')->nullable()->change(); // none for fill_and_go vehicles
            $table->timestamp('opened_at')->nullable()->after('status');
            $table->timestamp('departed_at')->nullable()->after('opened_at');
            $table->timestamp('full_at')->nullable()->after('departed_at');
            $table->timestamp('almost_full_notified_at')->nullable()->after('full_at');
            $table->index(['route_id', 'status', 'opened_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('moved_from_trip_id')->nullable()->after('trip_id')->constrained('trips')->nullOnDelete();
            $table->timestamp('moved_at')->nullable()->after('confirmed_by');
            $table->timestamp('no_show_at')->nullable()->after('moved_at');
            $table->foreignId('no_show_by')->nullable()->after('no_show_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_from_trip_id');
            $table->dropConstrainedForeignId('no_show_by');
            $table->dropColumn(['moved_at', 'no_show_at']);
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex(['route_id', 'status', 'opened_at']);
            $table->dropColumn(['opened_at', 'departed_at', 'full_at', 'almost_full_notified_at']);
        });
        Schema::table('pickup_points', function (Blueprint $table) {
            $table->dropColumn(['stop_order', 'is_main_station']);
        });
        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn(['mode', 'fare']);
        });
    }
};
