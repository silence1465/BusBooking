<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tickets sold over the counter by a station master: no online payment, and the
    // passenger's phone / pickup point may not be recorded.
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('source', 10)->default('online')->after('code')->index(); // online | station
            $table->foreignId('sold_by')->nullable()->after('source')->constrained('users')->nullOnDelete();
            $table->string('phone', 20)->nullable()->change();
            $table->foreignId('pickup_point_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sold_by');
            $table->dropColumn('source');
        });
    }
};
