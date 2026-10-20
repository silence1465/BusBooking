<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // The person who checks passengers in on the road is the driver; there is no conductor.
    public function up(): void
    {
        DB::table('users')->where('role', 'conductor')->update(['role' => 'driver']);
        DB::table('users')->where('email', 'conductor@busbooking.test')->update(['email' => 'driver@busbooking.test', 'name' => 'Yaw Driver']);
    }

    public function down(): void
    {
        DB::table('users')->where('role', 'driver')->update(['role' => 'conductor']);
    }
};
