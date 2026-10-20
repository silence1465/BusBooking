<?php

namespace Tests\SecurityAudit;

use App\Models\User;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Audit evidence: these tests assert vulnerable behavior, not desired security guarantees. */
class SecurityAuditTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    public function test_phone_aliases_bypass_account_login_limit(): void
    {
        $user = $this->makeUser(User::ROLE_STATION_MASTER);
        $user->phone = '0241234567';
        $user->save();
        $aliases = ['0241234567', '024-1234567', '024 1234567', '+233241234567', '+233 24 1234567', '233241234567'];
        foreach ($aliases as $alias) {
            for ($i = 0; $i < 5; $i++) {
                $this->postJson('/api/v1/auth/login', ['login' => $alias, 'password' => 'incorrect-password'])->assertStatus(422);
            }
            $this->postJson('/api/v1/auth/login', ['login' => $alias, 'password' => 'incorrect-password'])->assertStatus(429);
        }
    }

    public function test_driver_search_exposes_other_bus_on_same_route(): void
    {
        $victim = $this->makeTrip();
        $other = $this->makeTrip();
        $seller = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $victim);
        $booking = app(BookingService::class)->sellAtStation($victim['trip'], [
            'seat_numbers' => [1], 'passenger_name' => 'Audit Passenger', 'phone' => '0241234567',
            'pickup_point_id' => $victim['pickup']->id,
        ], $seller);
        $driver = $this->assignRoute($this->makeUser(User::ROLE_DRIVER), $victim);
        $driver->bus_id = $other['bus']->id;
        $driver->save();
        $this->assertFalse($driver->canWorkOnTrip($victim['trip']));
        $this->actingAs($driver, 'sanctum')->getJson('/api/v1/station/search?phone=0241234567')
            ->assertOk()->assertJsonPath('data.0.code', $booking->code)
            ->assertJsonPath('data.0.phone', '0241234567');
        $this->getJson('/api/v1/station/trips/'.$victim['trip']->id.'/manifest')->assertForbidden();
    }

    public function test_staff_password_reset_keeps_existing_bearer_token_valid(): void
    {
        $fixture = $this->makeTrip();
        $staff = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $fixture);
        $token = $staff->createToken('audit')->plainTextToken;
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/admin/station-masters/'.$staff->id, [
            'password' => 'new-audit-password-2026',
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/station/trips/'.$fixture['trip']->id.'/manifest')
            ->assertOk()->assertJsonPath('data.trip.id', $fixture['trip']->id);
    }

    public function test_payment_reference_alone_returns_passenger_and_ticket(): void
    {
        $fixture = $this->makeTrip();
        $created = $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture))->assertCreated();
        $this->postJson('/api/v1/payments/verify', ['reference' => $created->json('data.payment_reference')])
            ->assertOk()->assertJsonPath('data.booking.phone', '0241234567')
            ->assertJsonPath('data.booking.code', $created->json('data.booking.code'));
    }

    public function test_guest_can_hold_entire_bus_without_payment(): void
    {
        $fixture = $this->makeTrip(45);
        $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture, range(1, 45)))
            ->assertCreated()->assertJsonPath('data.booking.seat_total', 45)
            ->assertJsonPath('data.booking.status', 'pending');
        $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture, [1], ['phone' => '0551111111']))
            ->assertStatus(409);
    }
}
