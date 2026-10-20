<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One trip, several destinations: the price is decided by the destination alone, from the fixed start of the trip.
 * (The first version priced journeys between two stops. This replaces it with one price per destination.)
 *
 * Fares priced from the route's first stop keep their destination and price. Fares priced from a later stop
 * cannot be turned into a price from the start, so they are dropped and must be entered again.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kept = [];
        if (Schema::hasTable('trip_fares') && Schema::hasColumn('trip_fares', 'to_stop_id')) {
            $firstStop = DB::table('pickup_points')->selectRaw('route_id, min(stop_order) as first')->groupBy('route_id')->pluck('first', 'route_id');
            $stops = DB::table('pickup_points')->get()->keyBy('id');
            $routeOf = DB::table('trips')->pluck('route_id', 'id');

            foreach (DB::table('trip_fares')->orderBy('id')->get() as $f) {
                $from = $stops[$f->from_stop_id] ?? null;
                $to = $stops[$f->to_stop_id] ?? null;
                if ($from && $to && $from->stop_order === ($firstStop[$routeOf[$f->trip_id] ?? 0] ?? null)) {
                    $kept[$f->trip_id.'|'.mb_strtolower($to->name)] ??= ['trip_id' => $f->trip_id, 'destination' => $to->name, 'price' => $f->price, 'sort_order' => $to->stop_order];
                }
            }
            Schema::drop('trip_fares');
        }

        Schema::create('trip_fares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('destination', 100);
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['trip_id', 'destination']);
        });
        foreach ($kept as $row) {
            DB::table('trip_fares')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        if (Schema::hasColumn('bookings', 'dropoff_point_id')) {
            Schema::table('bookings', fn (Blueprint $table) => $table->dropConstrainedForeignId('dropoff_point_id'));
        }
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('destination', 100)->nullable()->after('pickup_point_id'); // only on trips with several destinations
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('destination'));
        Schema::dropIfExists('trip_fares');
    }
};
