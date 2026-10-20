<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\PickupPoint;
use App\Models\SeatHold;
use App\Models\SmsLog;
use App\Models\Trip;
use App\Models\User;
use App\Services\BusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class FillAndGoTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private BusRoute $route;
    private PickupPoint $main;
    private PickupPoint $stop;
    private User $sm;
    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);

        $this->route = BusRoute::create(['origin' => 'Circle', 'destination' => 'Nsawam', 'status' => 'active', 'mode' => 'fill_and_go', 'fare' => 20]);
        $this->main = $this->route->pickupPoints()->create(['name' => 'Circle', 'status' => 'active', 'stop_order' => 1, 'is_main_station' => true]);
        $this->stop = $this->route->pickupPoints()->create(['name' => 'Achimota', 'status' => 'active', 'stop_order' => 2, 'is_main_station' => false]);
        $this->sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $this->driver = $this->makeUser(User::ROLE_DRIVER);
        $this->sm->assignedRoutes()->attach($this->route->id);
        $this->driver->assignedRoutes()->attach($this->route->id);
    }

    private function bus(string $reg, int $seats = 6): Bus
    {
        return app(BusService::class)->create(['name' => 'Van '.$reg, 'registration_number' => $reg, 'seat_count' => $seats, 'status' => 'active']);
    }

    private function open(Bus $bus): int
    {
        return $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/routes/{$this->route->id}/open-boarding", ['bus_id' => $bus->id])
            ->assertCreated()->json('data.id');
    }

    /** An online passenger who has paid. Returns the booking code. */
    private function passenger(int $tripId, PickupPoint $pickup, int $seat, string $phone): string
    {
        $res = $this->postJson('/api/v1/bookings', [
            'trip_id' => $tripId, 'pickup_point_id' => $pickup->id, 'seat_numbers' => [$seat],
            'passenger_name' => 'Pax '.$seat, 'phone' => $phone,
        ])->assertCreated();
        $this->postJson('/api/v1/payments/verify', ['reference' => $res->json('data.payment_reference')])->assertOk();

        return $res->json('data.booking.code');
    }

    private function sms(string $needle): int
    {
        return SmsLog::where('message', 'like', "%$needle%")->count();
    }

    public function test_station_master_opens_a_vehicle_and_customers_can_book_it_with_no_departure_time(): void
    {
        $tripId = $this->open($this->bus('GT 1-26'));
        $trip = Trip::find($tripId);
        $this->assertSame('boarding', $trip->status);
        $this->assertNull($trip->departure_time);
        $this->assertEquals(20, $trip->price); // fare comes from the route, not the station master

        $date = today()->toDateString();
        $this->getJson("/api/v1/trips/search?from=Circle&to=Nsawam&date=$date")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.fill_and_go', true)
            ->assertJsonPath('data.0.departure_time_label', 'Leaves when full')->assertJsonPath('data.0.available_seats', 6);

        $this->passenger($tripId, $this->stop, 2, '0241111111');
        $this->assertSame(1, $this->sms('leaves when full'));
        $this->assertSame(1, $this->sms('text you when the bus leaves'));
    }

    public function test_opening_needs_a_fare_a_main_station_a_free_bus_and_an_assigned_route(): void
    {
        $bus = $this->bus('GT 2-26');
        $this->open($bus);
        $url = "/api/v1/station/routes/{$this->route->id}/open-boarding";

        $this->actingAs($this->sm, 'sanctum')->postJson($url, ['bus_id' => $bus->id])->assertStatus(409); // already boarding

        $this->route->update(['fare' => null]);
        $this->actingAs($this->sm, 'sanctum')->postJson($url, ['bus_id' => $this->bus('GT 3-26')->id])->assertStatus(422);

        $other = $this->makeUser(User::ROLE_STATION_MASTER); // not assigned to this route
        $this->route->update(['fare' => 20]);
        $this->actingAs($other, 'sanctum')->postJson($url, ['bus_id' => $this->bus('GT 4-26')->id])->assertForbidden();
    }

    public function test_almost_full_text_goes_once_to_main_station_passengers_who_have_not_checked_in(): void
    {
        $tripId = $this->open($this->bus('GT 5-26', 6));
        $this->passenger($tripId, $this->main, 1, '0242222222');
        $this->passenger($tripId, $this->stop, 2, '0243333333');
        $this->assertSame(0, $this->sms('almost full'));

        $this->passenger($tripId, $this->stop, 3, '0244444444'); // 3 seats left: threshold reached
        $this->assertSame(1, $this->sms('almost full'));
        $this->assertSame('0242222222', SmsLog::where('message', 'like', '%almost full%')->value('phone')); // only the station passenger

        $this->passenger($tripId, $this->stop, 4, '0245555555');
        $this->assertSame(1, $this->sms('almost full')); // not repeated
    }

    public function test_dispatch_is_blocked_by_unchecked_station_passengers_and_texts_pickup_passengers(): void
    {
        $tripId = $this->open($this->bus('GT 6-26'));
        $here = $this->passenger($tripId, $this->main, 1, '0246666666');
        $this->passenger($tripId, $this->stop, 2, '0247777777');
        $url = "/api/v1/station/trips/$tripId/dispatch";

        // The passenger at the station has not checked in: the vehicle cannot just leave.
        $this->actingAs($this->sm, 'sanctum')->postJson($url)->assertStatus(409)->assertJsonPath('unresolved', 1);

        $this->actingAs($this->sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $here])->assertJsonPath('data.status', 'valid');
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/bookings/$here/confirm-arrival")->assertOk();

        $this->actingAs($this->sm, 'sanctum')->postJson($url)->assertOk()->assertJsonPath('data.trip.status', 'departed');
        $this->assertSame(1, $this->sms('has left Circle. Be ready at Achimota'));

        // Gone: no more sales on it, online or at the counter.
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$tripId/sell", ['seat_numbers' => [5]])->assertStatus(422);
        $this->postJson('/api/v1/bookings', ['trip_id' => $tripId, 'pickup_point_id' => $this->stop->id, 'seat_numbers' => [5], 'passenger_name' => 'Late', 'phone' => '0248888888'])->assertStatus(422);
    }

    public function test_leaving_a_station_passenger_behind_moves_their_ticket_to_the_next_vehicle(): void
    {
        $first = $this->open($this->bus('GT 7-26'));
        $code = $this->passenger($first, $this->main, 3, '0249999999');
        $this->passenger($first, $this->stop, 4, '0241010101');

        // No next vehicle yet: the ticket has nowhere to go, so the vehicle cannot leave without them.
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$first/dispatch", ['leave_behind' => true])->assertStatus(409);
        $this->assertSame('boarding', Trip::find($first)->status);

        $second = $this->open($this->bus('GT 8-26'));
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$first/dispatch", ['leave_behind' => true])->assertOk();

        $moved = Booking::where('code', $code)->first();
        $this->assertSame($second, $moved->trip_id);
        $this->assertSame($first, $moved->moved_from_trip_id);
        $this->assertSame([3], $moved->seats->pluck('seat_number')->all());
        $this->assertSame(1, SeatHold::where('trip_id', $second)->where('status', 'booked')->count());
        $this->assertSame(1, $this->sms('has moved to the next vehicle'));
    }

    public function test_station_no_show_moves_once_then_forfeits(): void
    {
        $first = $this->open($this->bus('GT 9-26'));
        $second = $this->open($this->bus('GT 10-26'));
        $code = $this->passenger($first, $this->main, 2, '0242020202');
        $mark = fn () => $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/no-show");

        $mark()->assertOk()->assertJsonPath('data.outcome', 'moved');
        $this->assertSame($second, Booking::where('code', $code)->value('trip_id'));
        $this->assertSame(0, SeatHold::where('trip_id', $first)->count()); // the seat went back on sale

        // The moved ticket is on a vehicle that is still waiting in the queue: nobody can be a no-show yet.
        $mark()->assertStatus(409);

        // The first vehicle leaves, the second reaches the front, and the passenger misses that one too:
        // the ticket is gone and the seat is released.
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$first/sell", ['seat_numbers' => [6]])->assertCreated();
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$first/dispatch")->assertOk();
        $this->assertSame('boarding', Trip::find($second)->status);
        $mark()->assertOk()->assertJsonPath('data.outcome', 'forfeited');
        $this->assertSame('no_show', Booking::where('code', $code)->value('status'));
        $this->assertSame(0, SeatHold::where('trip_id', $second)->count());
    }

    public function test_pickup_no_show_is_forfeited_and_the_seat_stays_empty(): void
    {
        $tripId = $this->open($this->bus('GT 11-26'));
        $this->open($this->bus('GT 12-26')); // a next vehicle exists, but the ticket must still NOT move
        $here = $this->passenger($tripId, $this->main, 1, '0243030303');
        $code = $this->passenger($tripId, $this->stop, 2, '0243131313');

        // Not before the vehicle has left the station.
        $this->actingAs($this->driver, 'sanctum')->postJson("/api/v1/station/bookings/$code/no-show")->assertStatus(409);

        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/bookings/$here/confirm-arrival")->assertOk();
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$tripId/dispatch")->assertOk();

        $this->actingAs($this->driver, 'sanctum')->postJson("/api/v1/station/bookings/$code/no-show")
            ->assertOk()->assertJsonPath('data.outcome', 'forfeited');

        $b = Booking::where('code', $code)->first();
        $this->assertSame('no_show', $b->status);
        $this->assertSame($tripId, $b->trip_id);                                              // did not move
        $this->assertSame(1, SeatHold::where('booking_id', $b->id)->count());                // seat stays taken (empty)
        $this->actingAs($this->driver, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code])->assertJsonPath('data.status', 'no_show');
    }

    public function test_driver_checks_passengers_in_on_the_road_but_cannot_sell_or_dispatch(): void
    {
        $tripId = $this->open($this->bus('GT 13-26'));
        $here = $this->passenger($tripId, $this->main, 1, '0245050505');
        $code = $this->passenger($tripId, $this->stop, 2, '0245151515');
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/bookings/$here/confirm-arrival")->assertOk();
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$tripId/dispatch")->assertOk();

        $c = $this->actingAs($this->driver, 'sanctum');
        $c->postJson('/api/v1/station/verify', ['code' => $code])->assertJsonPath('data.status', 'valid');
        $c->postJson("/api/v1/station/bookings/$code/confirm-arrival")->assertOk();
        $c->getJson("/api/v1/station/trips/$tripId/manifest")->assertOk()->assertJsonPath('data.trip.fill_and_go', true);

        $c->postJson("/api/v1/station/trips/$tripId/sell", ['seat_numbers' => [4]])->assertForbidden();
        $c->postJson("/api/v1/station/trips/$tripId/dispatch")->assertForbidden();
        $c->postJson("/api/v1/station/routes/{$this->route->id}/open-boarding", ['bus_id' => 1])->assertForbidden();
        $c->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_a_departed_vehicle_cancels_unpaid_holds_and_admin_cannot_timetable_a_fill_and_go_route(): void
    {
        $tripId = $this->open($this->bus('GT 14-26'));
        $here = $this->passenger($tripId, $this->main, 1, '0246060606');
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/bookings/$here/confirm-arrival")->assertOk();

        // An unpaid hold exists when the vehicle leaves.
        $pending = $this->postJson('/api/v1/bookings', ['trip_id' => $tripId, 'pickup_point_id' => $this->stop->id, 'seat_numbers' => [5], 'passenger_name' => 'Slow', 'phone' => '0247070707'])->assertCreated();
        $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$tripId/dispatch")->assertOk();
        $this->assertSame('cancelled', Booking::where('code', $pending->json('data.booking.code'))->value('status'));
        $this->assertSame(0, SeatHold::where('seat_id', '>', 0)->where('status', 'held')->count());

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/trips', [
            'bus_id' => $this->bus('GT 15-26')->id, 'route_id' => $this->route->id,
            'travel_date' => today()->addDay()->toDateString(), 'departure_time' => '09:00', 'price' => 20,
        ])->assertStatus(422);
    }

    public function test_a_driver_linked_to_a_bus_only_works_on_that_bus_and_finds_the_current_trip(): void
    {
        $busA = $this->bus('GT 20-26');
        $busB = $this->bus('GT 21-26');
        $a = $this->open($busA);
        $b = $this->open($busB);
        $stationA = $this->passenger($a, $this->main, 1, '0241200001');
        $roadA = $this->passenger($a, $this->stop, 2, '0241200002');
        $stationB = $this->passenger($b, $this->main, 1, '0241200003');
        $roadB = $this->passenger($b, $this->stop, 2, '0241200004');
        foreach ([[$a, $stationA], [$b, $stationB]] as [$trip, $code]) {
            $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival")->assertOk();
            $this->actingAs($this->sm, 'sanctum')->postJson("/api/v1/station/trips/$trip/dispatch")->assertOk();
        }

        // Not linked yet: the driver sees every vehicle on the route and no "my vehicle".
        $d = $this->actingAs($this->driver, 'sanctum');
        $d->getJson('/api/v1/station/trips')->assertJsonCount(2, 'data');
        $d->getJson('/api/v1/station/my-vehicle')->assertJsonPath('data.linked', false);

        $this->driver->bus_id = $busA->id;
        $this->driver->save();

        // Linked: only their bus, and their phone is pointed at its current trip.
        $d->getJson('/api/v1/station/trips')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a);
        $d->getJson('/api/v1/station/my-vehicle')->assertOk()
            ->assertJsonPath('data.linked', true)->assertJsonPath('data.trip.id', $a)->assertJsonPath('data.bus.registration_number', 'GT 20-26');

        $d->getJson("/api/v1/station/trips/$a/manifest")->assertOk();
        $d->getJson("/api/v1/station/trips/$b/manifest")->assertForbidden();
        $d->postJson("/api/v1/station/bookings/$roadB/no-show")->assertForbidden();
        $d->postJson('/api/v1/station/verify', ['code' => $roadB])->assertJsonPath('data.status', 'not_assigned');

        $d->postJson('/api/v1/station/verify', ['code' => $roadA])->assertJsonPath('data.status', 'valid');
        $d->postJson("/api/v1/station/bookings/$roadA/confirm-arrival")->assertOk();
    }

    public function test_admin_links_a_driver_to_a_bus_but_not_a_station_master(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $bus = $this->bus('GT 22-26');
        $make = fn (string $role, string $email) => $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/station-masters', [
            'name' => 'Staff', 'email' => $email, 'password' => 'password123', 'role' => $role,
            'route_ids' => [$this->route->id], 'bus_id' => $bus->id,
        ])->assertCreated();

        $make('driver', 'drv@example.com')->assertJsonPath('data.bus_id', $bus->id);
        $make('station_master', 'stn@example.com')->assertJsonPath('data.bus_id', null); // a bus makes no sense for them

        $id = User::where('email', 'drv@example.com')->value('id');
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/station-masters/$id", ['bus_id' => null])->assertOk()->assertJsonPath('data.bus_id', null);
    }
}
