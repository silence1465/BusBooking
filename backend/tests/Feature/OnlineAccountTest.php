<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class OnlineAccountTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    private function sellOnline(array $f, array $seats): string
    {
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f, $seats))->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();

        return $ref;
    }

    public function test_online_sales_are_totalled_by_route_and_station_sales_are_left_out(): void
    {
        $f = $this->makeTrip();
        $this->sellOnline($f, [1, 2]);
        $ref = $this->sellOnline($f, [3]);

        // one is refunded, and one is sold at the counter (must not appear)
        Payment::where('reference', $ref)->firstOrFail()->forceFill(['status' => Payment::REFUNDED])->save();
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $f);
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", ['seat_numbers' => [4]])->assertCreated();

        $res = $this->actingAs($sm, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk();
        $res->assertJsonPath('data.totals.bookings', 2)
            ->assertJsonPath('data.totals.seats', 3)
            ->assertJsonPath('data.totals.refunded', 150)
            ->assertJsonPath('data.totals.station', 300)
            ->assertJsonCount(1, 'data.rows')
            // opening the route shows the car: it took all three online seats
            ->assertJsonCount(1, 'data.rows.0.vehicles')
            ->assertJsonPath('data.rows.0.vehicles.0.seats', 3)
            ->assertJsonPath('data.rows.0.vehicles.0.station', 300);
    }

    public function test_a_station_master_only_sees_the_routes_assigned_to_them(): void
    {
        $f = $this->makeTrip();
        $this->sellOnline($f, [1]);

        $other = $this->makeUser(User::ROLE_STATION_MASTER); // no routes assigned
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk()->assertJsonPath('data.totals.bookings', 0);

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk()->assertJsonPath('data.totals.bookings', 1);
    }

    public function test_the_commission_is_split_from_what_the_station_gets(): void
    {
        $f = $this->makeTrip(); // fare 150
        $f['trip']->route->update(['commission' => 10]);
        $this->sellOnline($f, [1, 2]);

        // a different rate for this vehicle type applies to later sales only
        $type = \App\Models\VehicleCategory::create(['name' => 'Express', 'station_due' => 0]);
        $f['bus']->forceFill(['category_id' => $type->id])->save();
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/routes/{$f['trip']->route_id}", [
            'origin' => 'Accra', 'destination' => 'Kumasi', 'commission' => 10, 'category_commissions' => [$type->id => 15],
        ])->assertOk()->assertJsonPath("data.category_commissions.{$type->id}", 15);
        $this->app['auth']->forgetGuards();
        $this->sellOnline($f, [3]);

        // 3 seats at 150 = 450. Commission 10 + 10 + 15 = 35. The station gets 415.
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk()
            ->assertJsonPath('data.totals.net', 450)
            ->assertJsonPath('data.totals.commission', 35)
            ->assertJsonPath('data.totals.station', 415)
            ->assertJsonPath('data.rows.0.vehicles.0.commission', 35);

        // the station master is told what the station gets, and nothing that reveals the commission
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $f);
        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($sm, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk()
            ->assertJsonPath('data.show_commission', false)
            ->assertJsonPath('data.totals.station', 415)
            ->assertJsonPath('data.rows.0.station', 415)
            ->assertJsonPath('data.rows.0.vehicles.0.station', 415);
        foreach (['data.totals', 'data.rows.0', 'data.rows.0.vehicles.0'] as $path) {
            foreach (['commission', 'net', 'gross'] as $key) {
                $this->assertArrayNotHasKey($key, $res->json($path));
            }
        }
    }

    public function test_a_trip_can_carry_its_own_commission(): void
    {
        $f = $this->makeTrip(); // fare 150, route commission 10
        $f['trip']->route->update(['commission' => 10]);
        $f['trip']->forceFill(['commission' => 25])->save(); // this trip: 25 a seat instead
        $this->sellOnline($f, [1, 2]);

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk()
            ->assertJsonPath('data.totals.commission', 50)
            ->assertJsonPath('data.totals.station', 250);

        // only an admin sees it on the trip; customers never do
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/trips')->assertJsonPath('data.0.commission', 25);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/trips/'.$f['trip']->id)->assertOk()->assertJsonMissingPath('data.trip.commission');
    }

    public function test_the_dashboard_shows_the_online_commission_not_the_sales(): void
    {
        $f = $this->makeTrip(12); // fare 150
        $f['trip']->route->update(['commission' => 10]);
        $this->sellOnline($f, [1, 2]);          // 2 seats online: commission 20 (sales 300)
        $ref = $this->sellOnline($f, [3]);      // refunded below: its commission no longer counts
        Payment::where('reference', $ref)->firstOrFail()->forceFill(['status' => Payment::REFUNDED])->save();
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $f);
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", ['seat_numbers' => [4]])->assertCreated(); // counter: none

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $res = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk()->assertJsonPath('data.todays_commission', 20);
        $this->assertSame(20, (int) $res->json('data.daily_commission.13.commission')); // the last day of the chart is today
    }
}
