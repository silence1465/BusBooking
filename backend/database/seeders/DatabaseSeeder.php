<?php

namespace Database\Seeders;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Trip;
use App\Models\User;
use App\Services\BusService;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(BusService $buses): void
    {
        $password = env('SEED_PASSWORD', 'password');
        if (app()->isProduction() && $password === 'password') {
            throw new RuntimeException('Set SEED_PASSWORD before seeding in production.');
        }

        foreach ([
            ['admin@busbooking.test', 'System Admin', User::ROLE_ADMIN, '0200000001'],
            ['station@busbooking.test', 'Kofi Station Master', User::ROLE_STATION_MASTER, '0200000002'],
            ['customer@busbooking.test', 'Ama Customer', User::ROLE_CUSTOMER, '0200000003'],
            ['driver@busbooking.test', 'Yaw Driver', User::ROLE_DRIVER, '0200000004'],
        ] as [$email, $name, $role, $phone]) {
            $user = User::firstOrNew(['email' => $email]);
            $user->fill(['name' => $name, 'phone' => $phone, 'password' => $password]);
            $user->role = $role;
            $user->is_active = true;
            $user->save();
        }

        $bus1 = Bus::where('registration_number', 'GT 1234-26')->first()
            ?? $buses->create(['name' => 'Golden Express 1', 'registration_number' => 'GT 1234-26', 'seat_count' => 45, 'status' => 'active']);
        $bus2 = Bus::where('registration_number', 'GR 5678-26')->first()
            ?? $buses->create(['name' => 'Golden Express 2', 'registration_number' => 'GR 5678-26', 'seat_count' => 33, 'status' => 'active']);

        $accraKumasi = BusRoute::firstOrCreate(['origin' => 'Accra', 'destination' => 'Kumasi'], ['status' => 'active']);
        $kumasiAccra = BusRoute::firstOrCreate(['origin' => 'Kumasi', 'destination' => 'Accra'], ['status' => 'active']);

        // Fill-and-go route: no timetable, vehicles leave when full. Circle is the main station.
        $nsawam = BusRoute::firstOrCreate(['origin' => 'Circle', 'destination' => 'Nsawam'], ['status' => 'active', 'mode' => 'fill_and_go', 'fare' => 20]);
        foreach ([['Circle', 1, true], ['Achimota', 2, false], ['Pokuase', 3, false]] as [$name, $order, $main]) {
            $nsawam->pickupPoints()->firstOrCreate(['name' => $name], ['status' => 'active', 'stop_order' => $order, 'is_main_station' => $main]);
        }

        // The seeded station master works on both seeded routes; the driver rides the fill-and-go one.
        User::where('email', 'station@busbooking.test')->first()?->assignedRoutes()->syncWithoutDetaching([$accraKumasi->id, $kumasiAccra->id, $nsawam->id]);
        User::where('email', 'driver@busbooking.test')->first()?->assignedRoutes()->syncWithoutDetaching([$nsawam->id]);
        User::where('email', 'driver@busbooking.test')->update(['bus_id' => $bus1->id]);

        foreach (['Circle' => 'Circle Bus Terminal', 'Achimota' => 'Achimota Overhead', 'Nsawam' => 'Nsawam Station'] as $name => $desc) {
            $accraKumasi->pickupPoints()->firstOrCreate(['name' => $name], ['description' => $desc, 'status' => 'active']);
        }
        foreach (['Kejetia' => 'Kejetia Terminal', 'Santasi' => 'Santasi Roundabout'] as $name => $desc) {
            $kumasiAccra->pickupPoints()->firstOrCreate(['name' => $name], ['description' => $desc, 'status' => 'active']);
        }

        // Trips: one later today (so the station master dashboard has something) plus the next days.
        $plan = [
            [0, '23:30', $bus1, $accraKumasi, 150],
            [1, '07:00', $bus1, $accraKumasi, 150],
            [1, '13:00', $bus2, $accraKumasi, 160],
            [1, '08:30', $bus2, $kumasiAccra, 150],
            [2, '07:00', $bus1, $accraKumasi, 150],
            [3, '07:00', $bus1, $kumasiAccra, 150],
        ];
        foreach ($plan as [$days, $time, $bus, $route, $price]) {
            Trip::firstOrCreate(
                ['bus_id' => $bus->id, 'travel_date' => today()->addDays($days)->toDateString(), 'departure_time' => $time.':00'],
                ['route_id' => $route->id, 'price' => $price, 'status' => Trip::SCHEDULED],
            );
        }
    }
}