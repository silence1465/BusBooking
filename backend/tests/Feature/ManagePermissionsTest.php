<?php

namespace Tests\Feature;

use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class ManagePermissionsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private array $f;
    private User $sm;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
        $this->f = $this->makeTrip();
        $this->sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $this->f);
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
    }

    private function toggle(string $key, bool $on): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/station-masters/{$this->sm->id}", [$key => $on])->assertOk()->assertJsonPath("data.$key", $on);
        $this->app['auth']->forgetGuards();
        $this->sm->refresh();
    }

    public function test_everything_is_off_until_an_admin_switches_it_on_for_that_station_master(): void
    {
        $as = fn () => $this->actingAs($this->sm, 'sanctum');
        $bus = ['name' => 'New Bus', 'registration_number' => 'GT 9-26', 'seat_count' => 20];

        $as()->postJson('/api/v1/manage/buses', $bus)->assertForbidden();
        $as()->postJson('/api/v1/manage/pickup-points', ['route_id' => $this->f['trip']->route_id, 'name' => 'Lapaz'])->assertForbidden();
        $as()->getJson('/api/v1/manage/trips')->assertForbidden();

        $this->toggle('can_manage_buses', true);
        $as()->postJson('/api/v1/manage/buses', $bus)->assertCreated();
        $as()->postJson('/api/v1/manage/pickup-points', ['route_id' => $this->f['trip']->route_id, 'name' => 'Lapaz'])->assertForbidden(); // only the buses switch is on

        $this->toggle('can_manage_buses', false);
        $as()->postJson('/api/v1/manage/buses', ['name' => 'Another', 'registration_number' => 'GT 10-26', 'seat_count' => 20])->assertForbidden();
    }

    public function test_it_is_per_person(): void
    {
        $other = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $this->f);
        $this->toggle('can_manage_trips', true);

        $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/manage/trips')->assertOk();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/manage/trips')->assertForbidden();
    }

    public function test_pickup_points_and_trips_stay_on_the_routes_assigned_to_them(): void
    {
        $this->toggle('can_manage_pickup_points', true);
        $this->toggle('can_manage_trips', true);
        $mine = $this->f['trip']->route_id;
        $theirs = BusRoute::create(['origin' => 'Tema', 'destination' => 'Ho', 'status' => 'active'])->id;
        $as = fn () => $this->actingAs($this->sm, 'sanctum');

        $as()->postJson('/api/v1/manage/pickup-points', ['route_id' => $mine, 'name' => 'Lapaz'])->assertCreated();
        $as()->postJson('/api/v1/manage/pickup-points', ['route_id' => $theirs, 'name' => 'Lapaz'])->assertForbidden();

        $trip = ['bus_id' => $this->f['bus']->id, 'travel_date' => now()->addDays(3)->toDateString(), 'departure_time' => '09:00', 'price' => 100];
        $as()->postJson('/api/v1/manage/trips', $trip + ['route_id' => $mine])->assertCreated();
        $as()->postJson('/api/v1/manage/trips', $trip + ['route_id' => $theirs, 'departure_time' => '10:00'])->assertForbidden();

        $as()->getJson('/api/v1/manage/routes')->assertOk()->assertJsonCount(1, 'data');
        $as()->getJson('/api/v1/manage/pickup-points')->assertOk()->assertJsonCount(2, 'data'); // the fixture's one, plus Lapaz
    }

    public function test_a_station_master_cannot_set_or_see_commission_or_station_dues(): void
    {
        $this->toggle('can_manage_trips', true);
        $this->toggle('can_manage_buses', true);
        $as = fn () => $this->actingAs($this->sm, 'sanctum');

        $trip = ['bus_id' => $this->f['bus']->id, 'route_id' => $this->f['trip']->route_id, 'travel_date' => now()->addDays(3)->toDateString(), 'departure_time' => '09:00', 'price' => 100, 'commission' => 40];
        $id = $as()->postJson('/api/v1/manage/trips', $trip)->assertCreated()->assertJsonMissingPath('data.commission')->json('data.id');
        $this->assertNull(Trip::find($id)->commission); // the 40 was ignored

        $as()->postJson('/api/v1/manage/buses', ['name' => 'Van', 'registration_number' => 'GT 77-26', 'seat_count' => 15, 'station_due_override' => 99])
            ->assertCreated()->assertJsonMissingPath('data.station_due_override');
        $this->assertNull(Bus::where('registration_number', 'GT 77-26')->first()->station_due_override);
    }

    public function test_only_station_masters_carry_the_switches_and_login_tells_the_page(): void
    {
        $this->toggle('can_manage_trips', true);
        $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions.trips', true)->assertJsonPath('data.permissions.buses', false);

        $driver = $this->makeUser(User::ROLE_DRIVER);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/station-masters/{$driver->id}", ['can_manage_trips' => true])->assertOk()->assertJsonPath('data.can_manage_trips', false);
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/manage/trips')->assertForbidden();
    }

    public function test_several_stops_can_be_added_at_once_in_order(): void
    {
        $route = $this->f['trip']->route_id;
        $first = (int) \App\Models\PickupPoint::where('route_id', $route)->max('stop_order');

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/pickup-points/bulk', [
            'route_id' => $route,
            'points' => [['name' => 'Circle', 'is_main_station' => true], ['name' => 'Kwame Nkrumah Interchange'], ['name' => 'Achimota', 'description' => 'Near the mall']],
        ])->assertCreated();

        $res->assertJsonCount(3, 'data')->assertJsonPath('data.0.stop_order', $first + 1)->assertJsonPath('data.2.stop_order', $first + 3)->assertJsonPath('data.0.is_main_station', true);
        $this->assertSame(3, \App\Models\PickupPoint::where('name', 'like', '%')->where('stop_order', '>', $first)->count());

        // an empty list or a nameless stop is refused, and nothing is saved
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/pickup-points/bulk', ['route_id' => $route, 'points' => [['name' => 'Ok'], ['name' => '']]])->assertStatus(422);

        // a station master needs the switch, and only their own routes
        $this->actingAs($this->sm, 'sanctum')->postJson('/api/v1/manage/pickup-points/bulk', ['route_id' => $route, 'points' => [['name' => 'Lapaz']]])->assertForbidden();
        $this->toggle('can_manage_pickup_points', true);
        $this->actingAs($this->sm, 'sanctum')->postJson('/api/v1/manage/pickup-points/bulk', ['route_id' => $route, 'points' => [['name' => 'Lapaz'], ['name' => 'Madina']]])->assertCreated()->assertJsonCount(2, 'data');
        $other = BusRoute::create(['origin' => 'Tema', 'destination' => 'Ho', 'status' => 'active'])->id;
        $this->actingAs($this->sm, 'sanctum')->postJson('/api/v1/manage/pickup-points/bulk', ['route_id' => $other, 'points' => [['name' => 'X']]])->assertForbidden();
    }

    public function test_a_station_master_can_list_the_stops_of_their_own_routes_only(): void
    {
        $this->toggle('can_manage_trips', true);
        $other = BusRoute::create(['origin' => 'Tema', 'destination' => 'Ho', 'status' => 'active']);
        $other->pickupPoints()->create(['name' => 'Lapaz', 'status' => 'active']);

        $names = $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/manage/stops')->assertOk()->json('data.*.name');
        $this->assertNotContains('Lapaz', $names);
        $this->assertContains('Achimota', $names);
    }
}
