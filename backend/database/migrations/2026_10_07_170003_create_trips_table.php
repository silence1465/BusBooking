<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bus_id')->constrained()->restrictOnDelete();
            $table->foreignId('route_id')->constrained('routes')->restrictOnDelete();
            $table->date('travel_date');
            $table->time('departure_time');
            $table->decimal('price', 10, 2);
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['route_id', 'travel_date', 'status']);
            $table->index(['travel_date', 'status']);
            $table->index(['bus_id', 'travel_date', 'departure_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
