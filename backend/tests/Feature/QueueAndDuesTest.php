<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\PickupPoint;
use App\Models\Trip;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Services\BusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class QueueAndDuesTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private BusRoute $route;
    private PickupPoint $main;
    private User $sm;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);

        $this->route = BusRoute::create(['origin' => 'Circle', 'destination' => 'Nsawam', 'status' => 'active', 'mode' => 'fill_and_go', 'fare' => 20]);
        $this->main = $this->route->pickupPoints()->create(['name' => 'Circle', 'status' => 'active', 'stop_order' => 1, 'is_main_station' => true]);
        $this->sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $this->sm->assignedRoutes()->attach($this->route->id);
    }

    private function bus(string $reg, ?VehicleCategory $category = null, ?float $override = null, int $seats = 4): Bus
    {
        $bus = app(BusService::class)->create(['name' => "Van $reg", 'registration_number' => $reg, 'seat_count' => $seats, 'status' => 'active']);
        $bus->category_id = $category?->id;
        $bus->station_due_override = $override;
        $bus->save();

        return $bus;
    }

    private function open(Bus $bus): int
    {
        return $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/routes/{$this->route->id}/open-boarding", ['bus_id' => $bus->id])->assertCreated()->json('data.id');
    }

    private function tripStatus(int $tripId): string
    {
        return Trip::find($tripId)->status;
    }

    private function sell(int $tripId, array $seats)
    {
        return $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$tripId/sell", ['seat_numbers' => $seats]);
    }

    private function dispatchTrip(int $tripId, array $body = [])
    {
        return $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$tripId/dispatch", $body);
    }

    public function test_vehicles_queue_up_and_the_next_one_steps_forward_when_the_front_one_leaves(): void
    {
        $a = $this->open($this->bus('Q-1'));
        $b = $this->open($this->bus('Q-2'));
        $c = $this->open($this->bus('Q-3'));

        $this->assertSame(['boarding', 'queued', 'queued'], [$this->tripStatus($a), $this->tripStatus($b), $this->tripStatus($c)]);
        $this->assertSame([1, 2, 3], [Trip::find($a)->queue_position, Trip::find($b)->queue_position, Trip::find($c)->queue_position]);

        // Customers are offered the front vehicle first, the next one only once the front is full.
        $date = today()->toDateString();
        $this->getJson("/api/v1/trips/search?from=Circle&to=Nsawam&date=$date")->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a)->assertJsonPath('data.0.queue_role', 'now')
            ->assertJsonPath('data.1.id', $b)->assertJsonPath('data.1.queue_role', 'next'); // shown faded, not bookable yet
        $this->sell($a, [1, 2, 3, 4])->assertCreated();
        $this->getJson("/api/v1/trips/search?from=Circle&to=Nsawam&date=$date")->assertJsonPath('data.0.id', $b)->assertJsonPath('data.1.id', $c);

        // The front vehicle leaves: the next one is promoted automatically.
        $this->dispatchTrip($a)->assertOk();
        $this->assertSame(['departed', 'boarding', 'queued'], [$this->tripStatus($a), $this->tripStatus($b), $this->tripStatus($c)]);

        $this->dispatchTrip($c)->assertStatus(409); // only the boarding vehicle can leave
    }

    public function test_the_station_master_can_reorder_the_queue_and_remove_an_empty_vehicle(): void
    {
        $a = $this->open($this->bus('R-1'));
        $b = $this->open($this->bus('R-2'));
        $c = $this->open($this->bus('R-3'));
        $queue = fn (int $id, string $action) => $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$id/queue", ['action' => $action]);

        $queue($c, 'up')->assertOk();                                  // C jumps ahead of B
        $this->assertSame([2, 3], [Trip::find($c)->queue_position, Trip::find($b)->queue_position]);
        $queue($c, 'up')->assertStatus(409);                           // already first in the queue (behind the boarding one)
        $queue($a, 'down')->assertStatus(409);                         // the boarding vehicle is not moved this way

        $queue($c, 'front')->assertOk();                               // the front vehicle is empty, so C may take its place
        $this->assertSame(['queued', 'queued', 'boarding'], [$this->tripStatus($a), $this->tripStatus($b), $this->tripStatus($c)]);

        $this->sell($c, [1])->assertCreated();
        $queue($a, 'front')->assertStatus(409);                        // C now has a passenger, so A cannot take the front back
    }

    public function test_the_front_vehicle_cannot_be_displaced_once_it_has_passengers_and_only_empty_vehicles_can_be_removed(): void
    {
        $a = $this->open($this->bus('S-1'));
        $b = $this->open($this->bus('S-2'));
        $queue = fn (int $id, string $action) => $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$id/queue", ['action' => $action]);

        $this->sell($a, [1])->assertCreated();
        $queue($b, 'front')->assertStatus(409);
        $queue($a, 'remove')->assertStatus(409);                       // it has a passenger

        $queue($b, 'remove')->assertOk();                              // empty and queued: fine
        $this->assertSame('cancelled', $this->tripStatus($b));
    }

    public function test_removing_the_empty_front_vehicle_promotes_the_next(): void
    {
        $a = $this->open($this->bus('T-1'));
        $b = $this->open($this->bus('T-2'));

        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$a/queue", ['action' => 'remove'])->assertOk();
        $this->assertSame(['cancelled', 'boarding'], [$this->tripStatus($a), $this->tripStatus($b)]);
    }

    public function test_station_dues_depend_on_the_category_or_a_special_rate_and_are_fixed_at_departure(): void
    {
        $mini = VehicleCategory::create(['name' => 'Minibus', 'station_due' => 10]);
        $big = VehicleCategory::create(['name' => 'Big bus', 'station_due' => 25]);

        $a = $this->open($this->bus('D-1', $mini));
        $b = $this->open($this->bus('D-2', $big));
        $c = $this->open($this->bus('D-3', $big, 18.5));   // a bus with its own special rate
        $d = $this->open($this->bus('D-4'));               // no category, no due
        foreach ([$a, $b, $c, $d] as $id) {
            $this->sell($id, [1])->assertCreated();
        }

        // Collected at the moment of dispatch for the first one, left unpaid for the others.
        $this->dispatchTrip($a, ['due_collected' => true])->assertOk()->assertJsonPath('data.station_due', 10)->assertJsonPath('data.due_paid', true);
        $this->dispatchTrip($b)->assertOk()->assertJsonPath('data.station_due', 25)->assertJsonPath('data.due_paid', false);
        $this->dispatchTrip($c)->assertOk()->assertJsonPath('data.station_due', 18.5);
        $this->dispatchTrip($d)->assertOk()->assertJsonPath('data.station_due', 0);

        // Changing the rate afterwards does not rewrite what was recorded.
        $big->update(['station_due' => 99]);
        $this->assertEquals(25, Trip::find($b)->station_due);

        // The unpaid due can be recorded as collected later, and undone.
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$b/due", ['paid' => true])->assertOk()->assertJsonPath('data.due_paid', true);
        $this->assertSame($this->sm->id, Trip::find($b)->due_collected_by);
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$b/due", ['paid' => false])->assertOk();
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$d/due", ['paid' => true])->assertStatus(409); // nothing was due
    }

    public function test_the_daily_departures_report_groups_vehicles_and_totals_the_dues(): void
    {
        $mini = VehicleCategory::create(['name' => 'Minibus', 'station_due' => 10]);
        $big = VehicleCategory::create(['name' => 'Big bus', 'station_due' => 25]);
        $ids = [
            $this->open($this->bus('E-1', $mini)),
            $this->open($this->bus('E-2', $mini)),
            $this->open($this->bus('E-3', $big)),
        ];
        $this->sell($ids[0], [1, 2])->assertCreated();
        $this->sell($ids[1], [1])->assertCreated();
        $this->sell($ids[2], [1, 2, 3])->assertCreated();

        $this->dispatchTrip($ids[0], ['due_collected' => true])->assertOk();
        $this->dispatchTrip($ids[1])->assertOk();
        $this->dispatchTrip($ids[2], ['due_collected' => true])->assertOk();

        $res = $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/station/departures')->assertOk();
        $res->assertJsonPath('data.totals.vehicles', 3)->assertJsonPath('data.totals.passengers', 6)
            ->assertJsonPath('data.totals.dues', 45)->assertJsonPath('data.totals.collected', 35)->assertJsonPath('data.totals.outstanding', 10);

        $rows = collect($res->json('data.rows'));
        $this->assertSame(['Big bus' => 1, 'Minibus' => 2], $rows->groupBy('category')->map->count()->sortKeys()->all());
        $this->assertSame('unpaid', $rows->firstWhere('registration', 'E-2')['due_status']);
        $this->assertSame('paid', $rows->firstWhere('registration', 'E-3')['due_status']);
        $this->assertSame(3, $rows->firstWhere('registration', 'E-3')['passengers']);

        // Another day has nothing.
        $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/station/departures?date='.today()->subDay()->toDateString())
            ->assertJsonPath('data.totals.vehicles', 0);
    }

    public function test_reports_are_limited_to_assigned_routes_and_drivers_cannot_see_dues(): void
    {
        $this->sell($this->open($this->bus('F-1')), [1]);
        $other = $this->makeUser(User::ROLE_STATION_MASTER); // not assigned to the route
        $driver = $this->makeUser(User::ROLE_DRIVER);
        $driver->assignedRoutes()->attach($this->route->id);

        $first = Trip::first()->id;
        $this->dispatchTrip($first)->assertOk();

        $this->actingAs($other, 'sanctum')->getJson('/api/v1/station/departures')->assertJsonPath('data.totals.vehicles', 0);
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/station/departures')->assertForbidden();
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/station/trips/$first/due", ['paid' => true])->assertForbidden();
        $this->actingAs($driver, 'sanctum')->postJson("/api/v1/station/trips/$first/queue", ['action' => 'remove'])->assertForbidden();

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/station/departures')->assertJsonPath('data.totals.vehicles', 1);
    }

    public function test_a_timetabled_trip_can_be_dispatched_too_so_the_days_record_is_complete(): void
    {
        $f = $this->makeTrip(12);
        $cat = VehicleCategory::create(['name' => 'Big bus', 'station_due' => 25]);
        $f['bus']->update(['category_id' => $cat->id]);
        $this->assignRoute($this->sm, $f);

        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/dispatch", ['due_collected' => true])
            ->assertOk()->assertJsonPath('data.station_due', 25);
        $this->assertSame('departed', $f['trip']->fresh()->status);
    }

    public function test_the_due_is_recorded_automatically_however_a_trip_is_marked_departed(): void
    {
        $big = VehicleCategory::create(['name' => 'Big bus', 'station_due' => 25]);
        $f = $this->makeTrip(12);
        $f['bus']->update(['category_id' => $big->id]);
        $admin = $this->makeUser(User::ROLE_ADMIN);

        // An admin simply sets the trip to departed: nobody pressed Dispatch, yet the due is on record.
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/trips/{$f['trip']->id}", ['status' => 'departed'])->assertOk();

        $trip = $f['trip']->fresh();
        $this->assertEquals(25, $trip->station_due);
        $this->assertNotNull($trip->departed_at);
        $this->assertNull($trip->due_paid_at); // outstanding until someone records the cash

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/station/departures')
            ->assertJsonPath('data.totals.vehicles', 1)->assertJsonPath('data.totals.outstanding', 25);

        // A later rate change does not touch what was recorded, and a second save does not re-record it.
        $big->update(['station_due' => 40]);
        $trip->update(['status' => Trip::COMPLETED]);
        $this->assertEquals(25, $trip->fresh()->station_due);
    }

    public function test_admin_manages_vehicle_categories_and_a_special_rate_on_a_bus(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $a = $this->actingAs($admin, 'sanctum');

        $id = $a->postJson('/api/v1/admin/vehicle-categories', ['name' => 'Coaster', 'station_due' => 15])->assertCreated()->json('data.id');
        $a->postJson('/api/v1/admin/vehicle-categories', ['name' => 'Coaster', 'station_due' => 20])->assertStatus(422); // names are unique
        $a->putJson("/api/v1/admin/vehicle-categories/$id", ['name' => 'Coaster', 'station_due' => 17])->assertOk();

        $bus = $a->postJson('/api/v1/admin/buses', [
            'name' => 'Big One', 'registration_number' => 'GT 99-26', 'seat_count' => 30, 'category_id' => $id, 'station_due_override' => 22.5,
        ])->assertCreated();
        $this->assertEquals(22.5, Bus::find($bus->json('data.id'))->stationDue());

        $a->putJson('/api/v1/admin/buses/'.$bus->json('data.id'), ['station_due_override' => null])->assertOk();
        $this->assertEquals(17, Bus::find($bus->json('data.id'))->stationDue()); // back to the category's due

        $a->deleteJson("/api/v1/admin/vehicle-categories/$id")->assertStatus(409); // a bus still uses it
        $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/admin/vehicle-categories')->assertForbidden();
    }

    public function test_a_vehicle_still_in_the_queue_overnight_is_in_todays_queue(): void
    {
        $a = $this->open($this->bus('GT 1-26'));
        Trip::whereKey($a)->update(['travel_date' => today()->subDay()->toDateString()]); // it was opened yesterday and never left

        // the station master sees it today, and customers can still book it
        $this->actingAs($this->sm, 'sanctum')->getJson('/api/v1/station/trips')->assertOk()->assertJsonPath('data.0.id', $a)->assertJsonPath('data.0.status', 'boarding');
        $this->assertSame(today()->toDateString(), Trip::find($a)->travel_date->toDateString());
        $this->getJson('/api/v1/trips/search?from=Circle&to=Nsawam&date='.today()->toDateString())->assertJsonPath('data.0.id', $a);

        // and the "already in the queue" message now matches what is on screen
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/routes/{$this->route->id}/open-boarding", ['bus_id' => Trip::find($a)->bus_id])->assertStatus(409);
    }
}
