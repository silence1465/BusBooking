<?php

namespace Tests\SecurityAudit;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Bounded local attack simulations; successful assertions include confirmed exploits. */
class PenetrationTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
        Http::preventStrayRequests();
    }

    public function test_guest_and_customer_cannot_access_privileged_routes(): void
    {
        $this->getJson('/api/v1/admin/bookings')->assertUnauthorized();
        $this->getJson('/api/v1/station/trips')->assertUnauthorized();
        $customer = $this->makeUser(User::ROLE_CUSTOMER);
        $this->withToken($customer->createToken('pentest')->plainTextToken);
        foreach (['/api/v1/admin/bookings', '/api/v1/admin/settings', '/api/v1/station/trips', '/api/v1/manage/buses'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }

    public function test_registration_cannot_mass_assign_admin_or_management_permissions(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Pentest User', 'email' => 'pentest@example.test', 'phone' => '0241234567',
            'password' => 'test-password-2026', 'password_confirmation' => 'test-password-2026',
            'role' => 'admin', 'is_active' => true, 'can_manage_buses' => true,
        ])->assertCreated()->assertJsonPath('data.user.role', 'customer');
        $this->withToken($response->json('data.token'))->getJson('/api/v1/admin/settings')->assertForbidden();
        $this->assertFalse((bool) User::first()->can_manage_buses);
    }

    public function test_customer_cannot_read_another_customers_bookings(): void
    {
        $fixture = $this->makeTrip();
        $victim = $this->makeUser(User::ROLE_CUSTOMER);
        $victimToken = $victim->createToken('victim')->plainTextToken;
        $reference = $this->withToken($victimToken)->postJson('/api/v1/bookings', $this->bookingPayload($fixture))
            ->assertCreated()->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $reference])->assertOk();
        $this->getJson('/api/v1/my/bookings')->assertOk()->assertJsonCount(1, 'data');
        $attacker = $this->makeUser(User::ROLE_CUSTOMER);
        $this->app['auth']->forgetGuards();
        $this->withToken($attacker->createToken('attacker')->plainTextToken)
            ->getJson('/api/v1/my/bookings')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_unassigned_station_cannot_check_in_or_modify_victim_trip(): void
    {
        $fixture = $this->makeTrip();
        $created = $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture))->assertCreated();
        $code = $created->json('data.booking.code');
        $this->postJson('/api/v1/payments/verify', ['reference' => $created->json('data.payment_reference')])->assertOk();
        $staff = $this->makeUser(User::ROLE_STATION_MASTER);
        $staff->can_manage_trips = true;
        $staff->save();
        $this->withToken($staff->createToken('pentest')->plainTextToken);
        $trip = $fixture['trip']->id;
        $this->getJson('/api/v1/station/trips/'.$trip.'/manifest')->assertForbidden();
        $this->postJson('/api/v1/station/bookings/'.$code.'/confirm-arrival')->assertStatus(409);
        $this->postJson('/api/v1/station/trips/'.$trip.'/dispatch')->assertForbidden();
        $this->putJson('/api/v1/manage/trips/'.$trip, ['price' => 1])->assertForbidden();
        $this->assertNull(Booking::first()->confirmed_at);
        $this->assertEquals(150, $fixture['trip']->fresh()->price);
    }

    public function test_driver_search_to_payment_reference_chain_exposes_ticket(): void
    {
        $victim = $this->makeTrip();
        $other = $this->makeTrip();
        $created = $this->postJson('/api/v1/bookings', $this->bookingPayload($victim))->assertCreated();
        $this->postJson('/api/v1/payments/verify', ['reference' => $created->json('data.payment_reference')])->assertOk();
        $driver = $this->assignRoute($this->makeUser(User::ROLE_DRIVER), $victim);
        $driver->bus_id = $other['bus']->id;
        $driver->save();
        $token = $driver->createToken('driver')->plainTextToken;
        $search = $this->withToken($token)->getJson('/api/v1/station/search?phone=0241234567')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/station/trips/'.$victim['trip']->id.'/manifest')->assertForbidden();
        $reference = $search->json('data.0.payment.reference');
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/v1/payments/verify', ['reference' => $reference])
            ->assertOk()->assertJsonPath('data.booking.phone', '0241234567')
            ->assertJsonPath('data.booking.code', $created->json('data.booking.code'));
    }

    public function test_price_and_identity_fields_cannot_be_overridden_in_guest_booking(): void
    {
        $fixture = $this->makeTrip();
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture, [1], [
            'amount' => 0.01, 'price' => 0, 'status' => 'confirmed', 'user_id' => $admin->id,
            'source' => 'station', 'commission' => -1000,
        ]))->assertCreated()->assertJsonPath('data.booking.status', 'pending');
        $booking = Booking::first();
        $this->assertEquals(150, $booking->amount);
        $this->assertNull($booking->user_id);
        $this->assertSame('online', $booking->source);
    }

    public function test_cross_trip_pickup_and_duplicate_seats_are_rejected(): void
    {
        $fixture = $this->makeTrip();
        $other = $this->makeTrip();
        $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture, [1], ['pickup_point_id' => $other['pickup']->id]))->assertUnprocessable();
        $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture, [1, 1]))->assertUnprocessable();
        $this->assertDatabaseCount('bookings', 0);
        $this->assertDatabaseCount('seat_holds', 0);
    }

    public function test_sql_payloads_do_not_bypass_login_or_ticket_lookup(): void
    {
        $fixture = $this->makeTrip();
        $created = $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture))->assertCreated();
        foreach (["' OR 1=1 --", "admin@test.test'--", "1 UNION SELECT 1"] as $payload) {
            $this->postJson('/api/v1/auth/login', ['login' => $payload, 'password' => 'irrelevant'])->assertUnprocessable();
            $this->getJson('/api/v1/tickets/'.$created->json('data.booking.code').'?phone='.rawurlencode($payload))->assertNotFound();
            $this->getJson('/api/v1/trips/search?from='.rawurlencode($payload))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_fake_success_and_unsigned_or_modified_webhooks_cannot_confirm_payment(): void
    {
        $fixture = $this->makeTrip();
        $created = $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture))->assertCreated();
        $reference = $created->json('data.payment_reference');
        config(['busbooking.payment.driver' => 'paystack', 'busbooking.payment.paystack.secret_key' => 'pentest-only-secret']);
        Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => ['status' => 'pending']], 200)]);
        $this->postJson('/api/v1/payments/verify', ['reference' => $reference, 'status' => 'success', 'amount' => 15000, 'currency' => 'GHS'])
            ->assertOk()->assertJsonPath('data.booking.status', 'pending');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
        $signature = hash_hmac('sha512', $body, 'pentest-only-secret');
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $body.' ')->assertUnauthorized();
        $this->assertSame('pending', Booking::first()->status);
        $this->assertSame('pending', Payment::first()->status);
    }

    public function test_gateway_amount_and_currency_mismatch_block_confirmation(): void
    {
        $fixture = $this->makeTrip();
        $reference = $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture))->assertCreated()->json('data.payment_reference');
        config(['busbooking.payment.driver' => 'paystack', 'busbooking.payment.paystack.secret_key' => 'pentest-only-secret']);
        foreach ([[1, 'GHS'], [15000, 'USD']] as [$amount, $currency]) {
            Http::fake(['api.paystack.co/*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'amount' => $amount, 'currency' => $currency,
            ]], 200)]);
            $this->postJson('/api/v1/payments/verify', ['reference' => $reference])->assertOk()->assertJsonPath('data.payment_status', 'failed');
            $this->assertSame('pending', Booking::first()->status);
        }
    }

    public function test_replayed_payment_and_signed_webhook_do_not_duplicate_confirmation(): void
    {
        $fixture = $this->makeTrip();
        $reference = $this->postJson('/api/v1/bookings', $this->bookingPayload($fixture))->assertCreated()->json('data.payment_reference');
        config(['busbooking.payment.paystack.secret_key' => 'pentest-only-secret']);
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/payments/verify', ['reference' => $reference])->assertOk();
            $this->call('POST', '/api/v1/payments/webhook', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'pentest-only-secret'),
            ], $body)->assertOk();
        }
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('sms_logs', 1);
        $this->assertDatabaseCount('seat_holds', 1);
    }

    public function test_php_upload_disguised_as_image_is_rejected(): void
    {
        $fixture = $this->makeTrip();
        $admin = $this->makeUser(User::ROLE_ADMIN);
        // A real temporary upload lets fileinfo inspect bytes; fake uploads infer MIME from their filename.
        $path = tempnam(sys_get_temp_dir(), 'bus-pentest-');
        file_put_contents($path, '<?php echo "pentest"; ?>');
        try {
            $this->withToken($admin->createToken('upload-test')->plainTextToken)->postJson(
                '/api/v1/admin/buses/'.$fixture['bus']->id.'/image',
                ['image' => new UploadedFile($path, 'payload.jpg', null, null, true)],
            )->assertUnprocessable();
        } finally {
            unlink($path);
        }
        $this->assertNull($fixture['bus']->fresh()->image_path);
    }

    public function test_ticket_lookup_limit_cannot_be_bypassed_by_changing_codes(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/api/v1/tickets/BUS-'.sprintf('%06d', $i).'?phone=0241234567')->assertNotFound();
        }
        $this->getJson('/api/v1/tickets/BUS-999999?phone=0551111111')->assertStatus(429);
    }
}
