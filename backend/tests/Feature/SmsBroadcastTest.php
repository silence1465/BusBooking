<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class SmsBroadcastTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
        $this->admin = $this->makeUser(User::ROLE_ADMIN);
    }

    private function paid(array $f, array $seats, string $phone): void
    {
        $ref = $this->postJson('/api/v1/bookings', $this->bookingPayload($f, $seats, ['phone' => $phone, 'passenger_name' => 'Ama '.$phone]))->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();
    }

    public function test_templates_can_be_saved_edited_and_deleted(): void
    {
        $a = $this->actingAs($this->admin, 'sanctum');
        $id = $a->postJson('/api/v1/admin/sms-templates', ['name' => 'Delay', 'body' => 'Hi {name}, the bus is late.'])->assertCreated()->json('data.id');
        $a->postJson('/api/v1/admin/sms-templates', ['name' => 'Delay', 'body' => 'x'])->assertStatus(422); // names are unique
        $a->putJson("/api/v1/admin/sms-templates/$id", ['name' => 'Delay', 'body' => 'Sorry {name}.'])->assertOk();
        $a->getJson('/api/v1/admin/sms-templates')->assertJsonPath('data.0.body', 'Sorry {name}.');
        $a->deleteJson("/api/v1/admin/sms-templates/$id")->assertOk();
        $a->getJson('/api/v1/admin/sms-templates')->assertJsonCount(0, 'data');
    }

    public function test_a_message_goes_to_each_passenger_on_a_route_with_their_details_filled_in(): void
    {
        $f = $this->makeTrip();
        $day = $f['trip']->travel_date->toDateString(); // the fixture trip is two days away
        $this->paid($f, [1], '0241111111');
        $this->paid($f, [2, 3], '0242222222');
        SmsLog::query()->delete(); // forget the booking confirmations

        $route = $f['trip']->route_id;
        $a = $this->actingAs($this->admin, 'sanctum');
        $a->getJson("/api/v1/admin/sms/recipients?route_id=$route&date=$day")->assertOk()->assertJsonPath('data.count', 2);

        // counts per route and per car, shown before sending; a single car can be picked
        $a->getJson("/api/v1/admin/sms/audience?date=$day")->assertOk()
            ->assertJsonPath('data.routes.0.passengers', 2)
            ->assertJsonPath('data.routes.0.trips.0.passengers', 2);
        $other = $f['trip']->replicate()->forceFill(['departure_time' => '09:00:00']);
        $other->save();
        $a->postJson('/api/v1/admin/sms/send', ['audience' => 'route', 'route_id' => $route, 'trip_id' => $other->id, 'date' => $day, 'message' => 'x ok'])->assertStatus(422); // nobody on that car

        $a->postJson('/api/v1/admin/sms/send', ['audience' => 'route', 'route_id' => $route, 'date' => $day, 'message' => 'Hi {name}, ticket {code} for {route} leaves from {pickup}.'])
            ->assertOk()->assertJsonPath('data.queued', 2);

        $this->assertSame(2, SmsLog::count());
        $log = SmsLog::where('phone', '0241111111')->first();
        $this->assertStringContainsString('Hi Ama 0241111111, ticket BUS-', $log->message);
        $this->assertStringContainsString('Accra to Kumasi', $log->message);
        $this->assertSame('sent', $log->status);
    }

    public function test_it_can_go_to_typed_numbers_and_rejects_bad_ones(): void
    {
        $a = $this->actingAs($this->admin, 'sanctum');
        $a->postJson('/api/v1/admin/sms/send', ['audience' => 'numbers', 'numbers' => ['0241234567', '+233 24 999 8888', '0241234567'], 'message' => 'Hello there'])
            ->assertOk()->assertJsonPath('data.queued', 2); // the repeated number is sent once
        $a->postJson('/api/v1/admin/sms/send', ['audience' => 'numbers', 'numbers' => ['12'], 'message' => 'Hello there'])->assertStatus(422);
        $a->postJson('/api/v1/admin/sms/send', ['audience' => 'numbers', 'numbers' => ['0241234567'], 'message' => 'Hi {name}'])->assertStatus(422);
    }

    public function test_only_an_admin_can_send(): void
    {
        $sm = $this->makeUser(User::ROLE_STATION_MASTER);
        $this->actingAs($sm, 'sanctum')->postJson('/api/v1/admin/sms/send', ['audience' => 'numbers', 'numbers' => ['0241234567'], 'message' => 'Hello'])->assertForbidden();
    }
}
