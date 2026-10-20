<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trip_id')->constrained()->restrictOnDelete();
            $table->foreignId('pickup_point_id')->constrained()->restrictOnDelete();
            $table->string('passenger_name');
            $table->string('phone', 20)->index();
            $table->string('email')->nullable();
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['trip_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('created_at');
        });

        Schema::create('booking_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('seat_number');
            $table->timestamps();

            $table->unique(['booking_id', 'seat_id']);
        });

        // One row per seat per trip that is currently held or booked.
        // The unique key is the hard guarantee that a seat can never be sold twice.
        Schema::create('seat_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10)->default('held'); // held | booked
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['trip_id', 'seat_id']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('paystack_reference')->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('channel', 30)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('seat_holds');
        Schema::dropIfExists('booking_seats');
        Schema::dropIfExists('bookings');
    }
};
