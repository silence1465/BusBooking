<?php

namespace Tests\Concerns;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\PickupPoint;
use App\Models\Trip;
use App\Models\User;
use App\Services\BusService;

trait BuildsFixtures
{
    protected function makeUser(string $role): User
    {
        $u = User::factory()->create();
        $u->role = $role;
        $u->is_active = true;
        $u->save();

        return $u;
    }

    /** Station masters only work on routes an admin assigned to them. */
    protected function assignRoute(User $user, array $fixture): User
    {
        $user->assignedRoutes()->syncWithoutDetaching([$fixture['trip']->route_id]);

        return $user;
    }

    /** @return array{trip: Trip, pickup: PickupPoint, bus: Bus} */
    protected function makeTrip(int $seats = 12): array
    {
        $bus = app(BusService::class)->create(['name' => 'Test Bus', 'registration_number' => 'GT '.random_int(1000, 9999).'-26', 'seat_count' => $seats, 'status' => 'active']);
        $route = BusRoute::create(['origin' => 'Accra', 'destination' => 'Kumasi', 'status' => 'active']);
        $pickup = $route->pickupPoints()->create(['name' => 'Achimota', 'status' => 'active']);
        $trip = Trip::create([
            'bus_id' => $bus->id, 'route_id' => $route->id,
            'travel_date' => now()->addDays(2)->toDateString(), 'departure_time' => '07:00:00',
            'price' => 150, 'status' => Trip::SCHEDULED,
        ]);

        return compact('trip', 'pickup', 'bus');
    }

    protected function bookingPayload(array $f, array $seats = [12], array $override = []): array
    {
        return array_merge([
            'trip_id' => $f['trip']->id,
            'pickup_point_id' => $f['pickup']->id,
            'seat_numbers' => $seats,
            'passenger_name' => 'Kwame Mensah',
            'phone' => '0241234567',
        ], $override);
    }
}
