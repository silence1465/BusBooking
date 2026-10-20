<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\SeatHold;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class BookingFlowTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    public function test_bus_creation_generates_seats_and_rejects_duplicate_registration(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);
        $payload = ['name' => 'Bus', 'registration_number' => 'GT 1234-26', 'seat_count' => 45];

        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/buses', $payload)->assertCreated();
        $this->assertDatabaseCount('seats', 45);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/buses', $payload)->assertStatus(422);
    }

    public function test_guest_can_book_and_seat_is_held_with_secure_code(): void
    {
        $f = $this->makeTrip();

        $res = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->assertCreated();

        $code = $res->json('data.booking.code');
        $this->assertMatchesRegularExpression('/^BUS-[A-Z0-9]{6}$/', $code);
        $this->assertSame('pending', $res->json('data.booking.status'));
        $this->assertEquals(150, $res->json('data.booking.amount'));
        $this->assertDatabaseHas('seat_holds', ['trip_id' => $f['trip']->id, 'status' => 'held']);
        $this->assertNotEmpty($res->json('data.authorization_url'));
    }

    public function test_same_seat_cannot_be_booked_twice(): void
    {
        $f = $this->makeTrip();

        $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->assertCreated();
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [12], ['phone' => '0551111111']))->assertStatus(409);

        $this->assertSame(1, SeatHold::where('trip_id', $f['trip']->id)->count());
        // A different seat is still fine.
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [11]))->assertCreated();
    }

    public function test_expired_hold_frees_the_seat(): void
    {
        $f = $this->makeTrip();
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->assertCreated();

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [12], ['phone' => '0552222222']))->assertCreated();

        $this->assertSame(1, Booking::where('status', 'cancelled')->where('cancellation_reason', 'expired')->count());
    }

    public function test_payment_verification_confirms_booking_and_sends_sms(): void
    {
        $f = $this->makeTrip();
        $res = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->assertCreated();
        $ref = $res->json('data.payment_reference');

        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])
            ->assertOk()->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.booking.status', 'confirmed');

        $this->assertDatabaseHas('seat_holds', ['trip_id' => $f['trip']->id, 'status' => 'booked', 'expires_at' => null]);
        $this->assertSame('sent', SmsLog::first()->status);
        $this->assertStringContainsString('Seat 12', SmsLog::first()->message);

        // Idempotent: a second verify (or the webhook) does not send another SMS.
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();
        $this->assertSame(1, SmsLog::count());
    }

    public function test_sms_failure_does_not_cancel_a_paid_booking(): void
    {
        config(['busbooking.sms.driver' => 'arkesel', 'busbooking.sms.arkesel.api_key' => null]);
        $f = $this->makeTrip();
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.payment_reference');

        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk()->assertJsonPath('data.booking.status', 'confirmed');
        $this->assertSame('failed', SmsLog::first()->status);
    }

    public function test_webhook_requires_valid_signature(): void
    {
        config(['busbooking.payment.paystack.secret_key' => 'sk_test_secret']);
        $f = $this->makeTrip();
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.payment_reference');
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $ref]]);

        $this->call('POST', '/api/v1/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->assertSame('pending', Payment::first()->status);

        $sig = hash_hmac('sha512', $body, 'sk_test_secret');
        $this->call('POST', '/api/v1/payments/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig], $body)->assertOk();
        $this->assertSame('paid', Payment::first()->status);
    }

    public function test_failed_payment_cancels_the_booking_and_frees_the_seat(): void
    {
        // A gateway that reports every payment as failed.
        $this->app->bind(\App\Services\Payments\PaymentGateway::class, fn () => new class implements \App\Services\Payments\PaymentGateway {
            public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata = []): array
            {
                return ['authorization_url' => 'https://pay.test/'.$reference, 'access_code' => 'x'];
            }

            public function verify(string $reference): \App\Services\Payments\VerificationResult
            {
                return new \App\Services\Payments\VerificationResult('failed');
            }
        });

        $f = $this->makeTrip();
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk()->assertJsonPath('data.payment_status', 'failed');

        $this->assertSame('failed', Payment::first()->status);
        $this->assertSame('cancelled', Booking::first()->status);
        $this->assertSame('payment_failed', Booking::first()->cancellation_reason);
        $this->assertSame(0, SeatHold::count());
    }

    public function test_admin_can_mark_a_payment_refunded(): void
    {
        $f = $this->makeTrip();
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();

        $admin = $this->makeUser(User::ROLE_ADMIN);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/payments/'.Payment::first()->id.'/refunded')->assertOk();
        $this->assertSame('refunded', Payment::first()->status);
    }

    public function test_ticket_requires_matching_phone(): void
    {
        $f = $this->makeTrip();
        $code = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.booking.code');

        $this->getJson("/api/v1/tickets/$code?phone=0559999999")->assertNotFound();
        $this->getJson("/api/v1/tickets/$code?phone=+233241234567")->assertOk()->assertJsonPath('data.code', $code);
    }

    public function test_multi_seat_booking_total(): void
    {
        $f = $this->makeTrip();
        $res = $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [3, 4]))->assertCreated();
        $this->assertEquals(300, $res->json('data.booking.amount'));
        $this->assertSame([3, 4], $res->json('data.booking.seats'));
    }

    public function test_search_returns_available_seats(): void
    {
        $f = $this->makeTrip(12);
        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [1, 2]))->assertCreated();

        $date = $f['trip']->travel_date->format('Y-m-d');
        $this->getJson("/api/v1/trips/search?from=Accra&to=Kumasi&date=$date")
            ->assertOk()->assertJsonPath('data.0.available_seats', 10)->assertJsonPath('data.0.price', 150);
        $this->getJson("/api/v1/trips/search?from=Accra&to=Tamale&date=$date")->assertOk()->assertJsonCount(0, 'data');
    }
}