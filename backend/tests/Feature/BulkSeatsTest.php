<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

class BulkSeatsTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    public function test_a_passenger_can_book_more_than_five_seats_in_one_booking(): void
    {
        $f = $this->makeTrip(12);

        $this->postJson('/api/v1/bookings', $this->bookingPayload($f, range(1, 8)))->assertCreated()->assertJsonPath('data.booking.amount', 1200);
    }

    public function test_a_station_master_can_sell_every_seat_at_once(): void
    {
        $f = $this->makeTrip(12);
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $f);

        $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", ['seat_numbers' => range(1, 12)])->assertCreated();
        $this->getJson("/api/v1/trips/{$f['trip']->id}")->assertJsonPath('data.trip.available_seats', 0);
    }
}
