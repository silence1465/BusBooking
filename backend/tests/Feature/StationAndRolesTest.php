<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class StationAndRolesTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    private function paidBooking(array $f, array $seats = [12]): string
    {
        $res = $this->postJson('/api/v1/bookings', $this->bookingPayload($f, $seats));
        $this->postJson('/api/v1/payments/verify', ['reference' => $res->json('data.payment_reference')]);

        return $res->json('data.booking.code');
    }

    public function test_station_master_cannot_use_admin_endpoints(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $f = $this->makeTrip();

        foreach (['buses', 'routes', 'trips', 'bookings', 'payments', 'station-masters', 'settings', 'dashboard'] as $path) {
            $this->actingAs($sm, 'sanctum')->getJson("/api/v1/admin/$path")->assertForbidden();
        }
        $this->actingAs($sm, 'sanctum')->putJson("/api/v1/admin/trips/{$f['trip']->id}", ['price' => 1])->assertForbidden();
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/admin/buses', [])->assertForbidden();
    }

    public function test_customers_and_guests_cannot_use_station_or_admin(): void
    {
        $customer = $this->makeUser(User::ROLE_CUSTOMER);

        $this->postJson('/api/v1/station/verify', ['code' => 'BUS-AAAAAA'])->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')->postJson('/api/v1/station/verify', ['code' => 'BUS-AAAAAA'])->assertForbidden();
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_verify_invalid_code(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => 'BUS-ZZZZZZ'])
            ->assertOk()->assertJsonPath('data.status', 'invalid');
    }

    public function test_verify_then_confirm_then_already_confirmed(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $sm2 = $this->makeUser(User::ROLE_STATION_MASTER);
        $f = $this->makeTrip();
        $this->assignRoute($sm, $f);
        $this->assignRoute($sm2, $f);
        $code = $this->paidBooking($f);

        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => strtolower($code)])
            ->assertOk()->assertJsonPath('data.status', 'valid')->assertJsonPath('data.booking.payment.status', 'paid');

        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival")
            ->assertOk()->assertJsonPath('data.booking.arrival.confirmed_by_id', $sm->id);

        $this->actingAs($sm2, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code])
            ->assertJsonPath('data.status', 'already_confirmed')->assertJsonPath('data.booking.arrival.confirmed_by', $sm->name);

        $this->actingAs($sm2, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival")->assertStatus(409);
    }

    public function test_unpaid_booking_cannot_be_confirmed(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $f = $this->makeTrip();
        $this->assignRoute($sm, $f);
        $code = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.booking.code');

        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code])->assertJsonPath('data.status', 'unpaid');
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival")->assertStatus(409);
    }

    public function test_admin_cancel_blocks_verification_and_frees_seat(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $f = $this->makeTrip();
        $this->assignRoute($sm, $f);
        $code = $this->paidBooking($f);
        $id = \App\Models\Booking::where('code', $code)->value('id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/bookings/$id/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code])->assertJsonPath('data.status', 'cancelled');
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival")->assertStatus(409);
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->assertCreated(); // seat 12 free again
    }

    public function test_phone_search_and_manifest(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $f = $this->makeTrip();
        $this->assignRoute($sm, $f);
        $code = $this->paidBooking($f);

        $this->actingAs($sm, 'sanctum')->getJson('/api/v1/station/search?phone=024 123 4567')
            ->assertOk()->assertJsonPath('data.0.code', $code);
        $this->actingAs($sm, 'sanctum')->getJson('/api/v1/station/search?phone=123')->assertStatus(422);

        $this->actingAs($sm, 'sanctum')->getJson("/api/v1/station/trips/{$f['trip']->id}/manifest")
            ->assertOk()->assertJsonCount(1, 'data.bookings');
        $this->actingAs($sm, 'sanctum')->getJson("/api/v1/station/trips/{$f['trip']->id}/manifest?q=nobody")
            ->assertOk()->assertJsonCount(0, 'data.bookings');
    }

    public function test_station_master_can_sell_seats_and_online_availability_updates(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $f = $this->makeTrip(12);
        $this->assignRoute($sm, $f);
        $date = $f['trip']->travel_date->format('Y-m-d');

        $res = $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", [
            'seat_numbers' => [3, 4], 'passenger_name' => 'Walk In', 'price' => 1, // a client-sent price is ignored
        ])->assertCreated()
            ->assertJsonPath('data.source', 'station')
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.payment.channel', 'cash');
        $this->assertEquals(300, $res->json('data.amount')); // 2 x 150, the trip price

        // Online customers now see those seats as taken and cannot book them.
        $this->getJson("/api/v1/trips/search?from=Accra&to=Kumasi&date=$date")->assertJsonPath('data.0.available_seats', 10);
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [4]))->assertStatus(409);

        // The same seat cannot be sold twice at the station either.
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", ['seat_numbers' => [3]])->assertStatus(409);

        // The sale is a normal confirmed booking: verifiable. A counter sale carries no online commission.
        $code = $res->json('data.code');
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code])->assertJsonPath('data.status', 'valid');
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertJsonPath('data.todays_bookings', 1)->assertJsonPath('data.todays_commission', 0);
    }

    public function test_only_station_staff_can_sell_seats(): void
    {
        $f = $this->makeTrip();
        $url = "/api/v1/station/trips/{$f['trip']->id}/sell";

        $this->postJson($url, ['seat_numbers' => [1]])->assertUnauthorized();
        $this->actingAs($this->makeUser(User::ROLE_CUSTOMER), 'sanctum')->postJson($url, ['seat_numbers' => [1]])->assertForbidden();
    }

    public function test_station_master_only_sees_and_sells_assigned_routes(): void
    {
        $mine = $this->makeTrip(12);
        $other = $this->makeTrip(12);
        $other['trip']->route->update(['origin' => 'Tamale', 'destination' => 'Bolgatanga']);
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $mine);
        $date = $mine['trip']->travel_date->format('Y-m-d');

        // Only the assigned route is listed.
        $this->actingAs($sm, 'sanctum')->getJson("/api/v1/station/trips?date=$date")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine['trip']->id);

        // Trips on other routes are forbidden: seat map, manifest and selling.
        $url = "/api/v1/station/trips/{$other['trip']->id}";
        $this->actingAs($sm, 'sanctum')->getJson("$url/seats")->assertForbidden();
        $this->actingAs($sm, 'sanctum')->getJson("$url/manifest")->assertForbidden();
        $this->actingAs($sm, 'sanctum')->postJson("$url/sell", ['seat_numbers' => [1]])->assertForbidden();
        $this->assertDatabaseCount('seat_holds', 0);

        // The assigned route works.
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$mine['trip']->id}/sell", ['seat_numbers' => [1]])->assertCreated();

        // A booking on another route is neither revealed, confirmable, nor found by phone search.
        $code = $this->paidBooking($other);
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code])
            ->assertJsonPath('data.status', 'not_assigned')->assertJsonPath('data.booking', null)
            // It tells the station master where the ticket IS for, without exposing the passenger.
            ->assertJsonPath('data.ticket_trip.origin', 'Tamale')->assertJsonPath('data.ticket_trip.destination', 'Bolgatanga')
            ->assertJsonPath('data.ticket_trip.ticket_state', 'valid');
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival")->assertStatus(409);
        $this->actingAs($sm, 'sanctum')->getJson('/api/v1/station/search?phone=0241234567')->assertJsonCount(0, 'data');
    }

    public function test_ticket_for_another_trip_is_flagged_even_on_an_assigned_route(): void
    {
        $a = $this->makeTrip(12);
        $b = $this->makeTrip(12); // a second trip the same station master also handles
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $this->assignRoute($sm, $a);
        $this->assignRoute($sm, $b);
        $code = $this->paidBooking($a);

        // Boarding trip B with a ticket for trip A: warned, details shown, cannot be confirmed.
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code, 'trip_id' => $b['trip']->id])
            ->assertJsonPath('data.status', 'wrong_trip')->assertJsonPath('data.booking.code', $code)
            ->assertJsonPath('data.ticket_trip.departure_time_label', '7:00 AM');
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival", ['trip_id' => $b['trip']->id])->assertStatus(409);

        // Boarding the right trip (or none selected) works.
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/station/verify', ['code' => $code, 'trip_id' => $a['trip']->id])->assertJsonPath('data.status', 'valid');
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/bookings/$code/confirm-arrival", ['trip_id' => $a['trip']->id])->assertOk();
    }

    public function test_station_master_with_no_routes_sees_nothing_and_admin_sees_all(): void
    {
        $f = $this->makeTrip(12);
        $date = $f['trip']->travel_date->format('Y-m-d');

        $sm = $this->makeUser(User::ROLE_STATION_MASTER); // not assigned to anything
        $this->actingAs($sm, 'sanctum')->getJson("/api/v1/station/trips?date=$date")->assertJsonCount(0, 'data');
        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", ['seat_numbers' => [1]])->assertForbidden();

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/station/trips?date=$date")->assertJsonCount(1, 'data');
    }

    public function test_admin_assigns_routes_to_station_master(): void
    {
        $f = $this->makeTrip();
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/station-masters', [
            'name' => 'Ama Agent', 'email' => 'agent@example.com', 'password' => 'password123', 'route_ids' => [$f['trip']->route_id],
        ])->assertCreated()->assertJsonPath('data.route_ids.0', $f['trip']->route_id);

        $id = $res->json('data.id');
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/station-masters/$id", ['route_ids' => []])->assertOk()->assertJsonCount(0, 'data.route_ids');
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/station-masters/$id", ['route_ids' => [99999]])->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/station-masters')->assertOk()->assertJsonPath('data.0.email', 'agent@example.com');
    }

    public function test_login_and_disabled_account(): void
    {
        $u = $this->makeUser(User::ROLE_STATION_MASTER);
        $u->password = 'secret-pass-1';
        $u->save();

        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret-pass-1'])->assertOk()->assertJsonStructure(['data' => ['token', 'user']]);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong'])->assertStatus(422);

        // Phone number sign-in, in any common format.
        $u->phone = '0241234567';
        $u->save();
        $this->postJson('/api/v1/auth/login', ['login' => '0241234567', 'password' => 'secret-pass-1'])->assertOk()->assertJsonPath('data.user.id', $u->id);
        $this->postJson('/api/v1/auth/login', ['login' => '+233 24 123 4567', 'password' => 'secret-pass-1'])->assertOk();
        $this->postJson('/api/v1/auth/login', ['login' => '0241234567', 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['login' => '0559999999', 'password' => 'secret-pass-1'])->assertStatus(422);

        // A phone number can belong to one account only.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Other', 'email' => 'other@example.com', 'phone' => '024 123 4567',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        $u->is_active = false;
        $u->save();
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'secret-pass-1'])->assertStatus(422);
    }

    public function test_registration_cannot_choose_role(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Hacker', 'email' => 'h@example.com', 'phone' => '0241112222',
            'password' => 'password123', 'password_confirmation' => 'password123', 'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.user.role', 'customer');
    }

    public function test_admin_trip_creation_and_dashboard(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $f = $this->makeTrip();
        $this->paidBooking($f);

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/trips', [
            'bus_id' => $f['bus']->id, 'route_id' => $f['trip']->route_id,
            'travel_date' => now()->addDays(5)->toDateString(), 'departure_time' => '09:00', 'price' => 99,
        ])->assertCreated();
        // Same bus, same slot is rejected.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/trips', [
            'bus_id' => $f['bus']->id, 'route_id' => $f['trip']->route_id,
            'travel_date' => now()->addDays(5)->toDateString(), 'departure_time' => '09:00', 'price' => 99,
        ])->assertStatus(422);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk()
            ->assertJsonPath('data.todays_bookings', 1)->assertJsonPath('data.todays_commission', 0)
            ->assertJsonPath('data.total_passengers', 1);
    }

    public function test_the_counter_keeps_selling_after_the_departure_time_until_the_day_ends(): void
    {
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
        $f = $this->makeTrip(12); // leaves at 07:00, two days from now
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $f);
        $url = "/api/v1/station/trips/{$f['trip']->id}/sell";
        $day = $f['trip']->travel_date->copy();

        // 3pm on the day: the bus is late. Online has closed, the counter has not.
        $this->travelTo($day->copy()->setTime(15, 0));
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [1]))->assertStatus(422);
        $this->actingAs($sm, 'sanctum')->getJson("/api/v1/station/trips/{$f['trip']->id}/seats")->assertOk();
        $this->actingAs($sm, 'sanctum')->postJson($url, ['seat_numbers' => [2]])->assertCreated();

        // 11:30pm: still the same day
        $this->travelTo($day->copy()->setTime(23, 30));
        $this->actingAs($sm, 'sanctum')->postJson($url, ['seat_numbers' => [3]])->assertCreated();

        // the next morning the day has ended
        $this->travelTo($day->copy()->addDay()->setTime(6, 0));
        $this->actingAs($sm, 'sanctum')->postJson($url, ['seat_numbers' => [4]])->assertStatus(422);
        $this->actingAs($sm, 'sanctum')->getJson("/api/v1/station/trips/{$f['trip']->id}/seats")->assertNotFound();
        $this->travelBack();
    }
}
