<?php

namespace Tests\Feature;

use App\Jobs\DeliverSms;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class SmsDeliveryTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    private function paidBooking(): array
    {
        $f = $this->makeTrip();
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f))->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();

        return $f;
    }

    public function test_a_message_type_can_be_switched_off(): void
    {
        \App\Models\Setting::create(['key' => 'sms_tickets', 'value' => '0']);

        $this->paidBooking();

        $log = SmsLog::first();
        $this->assertSame('skipped', $log->status);
        $this->assertNull($log->sent_at);
    }

    public function test_the_text_is_delivered_right_after_the_response_without_a_worker(): void
    {
        $this->paidBooking();

        $log = SmsLog::first();
        $this->assertSame('sent', $log->status);
        $this->assertNotNull($log->sent_at);
    }

    public function test_in_queue_mode_the_request_only_queues_the_text_and_a_worker_sends_it(): void
    {
        Queue::fake();
        config(['busbooking.sms.delivery' => 'queue']);

        $this->paidBooking();

        // The booking is already confirmed and the text is waiting on the "sms" queue, not sent inline.
        $this->assertSame('confirmed', \App\Models\Booking::first()->status);
        $log = SmsLog::first();
        $this->assertSame('queued', $log->status);
        Queue::assertPushedOn('sms', DeliverSms::class, fn (DeliverSms $job) => $job->logId === $log->id);

        (new DeliverSms($log->id))->handle(app(SmsService::class));
        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_a_failed_first_attempt_is_retried_once_straight_away(): void
    {
        config(['busbooking.sms.driver' => 'arkesel', 'busbooking.sms.arkesel.api_key' => 'test-key']);
        Http::fake(['sms.arkesel.com/*' => Http::sequence()
            ->push('{"status":"error"}', 500)
            ->push('{"status":"success"}', 200)]);

        $this->paidBooking();

        Http::assertSentCount(2);
        $this->assertSame('sent', SmsLog::first()->status);
    }

    public function test_a_provider_that_keeps_failing_is_recorded_without_touching_the_booking(): void
    {
        config(['busbooking.sms.driver' => 'arkesel', 'busbooking.sms.arkesel.api_key' => 'test-key']);
        Http::fake(['sms.arkesel.com/*' => Http::response('{"status":"error"}', 500)]);

        $this->paidBooking();

        $this->assertSame('failed', SmsLog::first()->status);
        $this->assertSame('confirmed', \App\Models\Booking::first()->status);
    }
}
