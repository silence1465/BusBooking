<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which routes a station master may work on. Admins are not restricted.
    public function up(): void
    {
        Schema::create('route_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'route_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_user');
    }
};
