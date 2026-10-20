<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fill-and-go vehicles wait in a queue (the front one is "boarding", the rest "queued"), and every
     * vehicle that departs pays a station due that depends on its category (or a special rate for that bus).
     */
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('station_due', 10, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('buses', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('status')->constrained('vehicle_categories')->nullOnDelete();
            $table->decimal('station_due_override', 10, 2)->nullable()->after('category_id');
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->unsignedInteger('queue_position')->nullable()->after('opened_at');
            // Snapshot taken at departure, so changing a rate later never rewrites history.
            $table->decimal('station_due', 10, 2)->nullable()->after('departed_at');
            $table->timestamp('due_paid_at')->nullable()->after('station_due');
            $table->foreignId('due_collected_by')->nullable()->after('due_paid_at')->constrained('users')->nullOnDelete();
            $table->index(['route_id', 'status', 'queue_position']);
            $table->index('departed_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropIndex(['route_id', 'status', 'queue_position']);
            $table->dropIndex(['departed_at']);
            $table->dropConstrainedForeignId('due_collected_by');
            $table->dropColumn(['queue_position', 'station_due', 'due_paid_at']);
        });
        Schema::table('buses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn('station_due_override');
        });
        Schema::dropIfExists('vehicle_categories');
    }
};
