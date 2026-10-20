<?php

namespace Tests\Feature;

use App\Services\Payments\PaymentGateway;
use App\Services\Payments\VerificationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Paystack refuses some email addresses. That must never stop a customer from paying. */
class PaymentEmailTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private function gateway(array &$seen, bool $refuseEverythingButGuests = false): PaymentGateway
    {
        return new class($seen, $refuseEverythingButGuests) implements PaymentGateway
        {
            public function __construct(private array &$seen, private bool $strict) {}

            public function initialize(string $email, int $amountMinor, string $currency, string $reference, string $callbackUrl, array $metadata = []): array
            {
                $this->seen[] = $email;
                if ($this->strict && ! str_contains($email, 'guest.')) {
                    throw new \RuntimeException('Paystack initialize failed: Invalid Email Address Passed');
                }

                return ['authorization_url' => 'https://checkout.example/pay', 'access_code' => 'x'];
            }

            public function verify(string $reference): VerificationResult
            {
                return new VerificationResult('pending');
            }
        };
    }

    public function test_a_made_up_email_domain_is_replaced_by_a_guest_address(): void
    {
        $seen = [];
        $this->app->instance(PaymentGateway::class, $this->gateway($seen));
        $f = $this->makeTrip();

        $res = $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [1], ['email' => 'admin@busbooking.test']))->assertCreated();

        $this->assertSame('https://checkout.example/pay', $res->json('data.authorization_url'));
        $this->assertStringEndsWith('@guest.busbooking.app', $seen[0]); // .test never even reaches Paystack
        $this->assertSame('admin@busbooking.test', $res->json('data.booking.email')); // what the customer typed is still on the booking
    }

    public function test_if_paystack_refuses_the_email_we_retry_with_the_guest_address(): void
    {
        $seen = [];
        $this->app->instance(PaymentGateway::class, $this->gateway($seen, true));
        $f = $this->makeTrip();

        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, [1], ['email' => 'kofi@gmail.com']))->assertCreated();

        $this->assertSame('kofi@gmail.com', $seen[0]);
        $this->assertStringEndsWith('@guest.busbooking.app', $seen[1]);
    }
}
