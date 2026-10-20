<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\SeatHold;
use App\Models\SmsLog;
use App\Models\User;
use App\Models\VehicleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** Express and Voxy have open seating: passengers book how many seats, never which seat. */
class OpenSeatingTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);
    }

    private function openSeatingTrip(int $seats = 5): array
    {
        $f = $this->makeTrip($seats);
        $voxy = VehicleCategory::create(['name' => 'Voxy', 'station_due' => 10, 'seat_selection' => false]);
        $f['bus']->update(['category_id' => $voxy->id]);

        return $f;
    }

    private function book(array $f, array $extra)
    {
        return $this->postJson('/api/v1/bookings', array_merge([
            'trip_id' => $f['trip']->id, 'pickup_point_id' => $f['pickup']->id,
            'passenger_name' => 'Kwame Mensah', 'phone' => '0241234567',
        ], $extra));
    }

    public function test_the_trip_tells_the_app_not_to_show_a_seat_map(): void
    {
        $open = $this->openSeatingTrip();
        $this->getJson("/api/v1/trips/{$open['trip']->id}")->assertOk()->assertJsonPath('data.trip.bus.seat_selection', false);

        $normal = $this->makeTrip();
        $this->getJson("/api/v1/trips/{$normal['trip']->id}")->assertOk()->assertJsonPath('data.trip.bus.seat_selection', true);
    }

    public function test_customers_book_a_number_of_seats_and_the_system_assigns_them(): void
    {
        $f = $this->openSeatingTrip();

        $first = $this->book($f, ['seat_count' => 2])->assertCreated();
        $this->assertSame([1, 2], $first->json('data.booking.seats'));
        $this->assertEquals(300, $first->json('data.booking.amount')); // 2 x 150

        $second = $this->book($f, ['seat_count' => 1, 'phone' => '0551111111'])->assertCreated();
        $this->assertSame([3], $second->json('data.booking.seats'));
        $this->assertSame(3, SeatHold::where('trip_id', $f['trip']->id)->count());
    }

    public function test_it_refuses_more_seats_than_are_left_and_never_hands_out_a_seat_twice(): void
    {
        $f = $this->openSeatingTrip(5);

        $this->book($f, ['seat_count' => 4])->assertCreated();
        $this->book($f, ['seat_count' => 2, 'phone' => '0552222222'])->assertStatus(409)->assertJsonPath('message', 'Only 1 seat left on this vehicle.');
        $this->book($f, ['seat_count' => 1, 'phone' => '0553333333'])->assertCreated();
        $this->book($f, ['seat_count' => 1, 'phone' => '0554444444'])->assertStatus(409)->assertJsonPath('message', 'This vehicle is full.');

        $this->assertSame(5, SeatHold::where('trip_id', $f['trip']->id)->distinct('seat_id')->count('seat_id'));
    }

    public function test_the_confirmation_text_does_not_promise_a_seat_number(): void
    {
        $f = $this->openSeatingTrip();
        $ref = $this->book($f, ['seat_count' => 2])->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();

        $message = SmsLog::first()->message;
        $this->assertStringContainsString('2 seats, sit anywhere', $message);
        $this->assertStringNotContainsString('Seat 1', $message);
    }

    public function test_a_vehicle_with_seat_selection_still_needs_seat_numbers(): void
    {
        $f = $this->makeTrip();

        $this->book($f, ['seat_count' => 2])->assertStatus(422); // "Please choose your seat."
        $this->book($f, ['seat_numbers' => [12]])->assertCreated()->assertJsonPath('data.booking.seats.0', 12);
    }

    public function test_the_station_sells_open_seating_by_quantity_too(): void
    {
        $f = $this->openSeatingTrip();
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $f);

        $res = $this->actingAs($sm, 'sanctum')->postJson("/api/v1/station/trips/{$f['trip']->id}/sell", ['seat_count' => 3])->assertCreated();
        $this->assertSame([1, 2, 3], $res->json('data.seats'));
        $this->assertSame(3, $res->json('data.seat_total'));
        $this->assertFalse($res->json('data.trip.seat_selection'));

        // Online bookings continue from where the counter left off.
        $this->book($f, ['seat_count' => 1])->assertCreated()->assertJsonPath('data.booking.seats.0', 4);
        $this->assertSame(1, Booking::where('source', 'online')->count());
    }

    public function test_admin_sets_whether_a_vehicle_type_allows_seat_selection(): void
    {
        $admin = $this->makeUser(User::ROLE_ADMIN);

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/vehicle-categories', ['name' => 'Express', 'station_due' => 15, 'seat_selection' => false])
            ->assertCreated()->assertJsonPath('data.seat_selection', false)->json('data.id');
        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/admin/vehicle-categories/$id", ['name' => 'Express', 'station_due' => 15, 'seat_selection' => true])
            ->assertOk()->assertJsonPath('data.seat_selection', true);

        // Types default to seat selection when the setting is not sent.
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/vehicle-categories', ['name' => 'Bus', 'station_due' => 25])
            ->assertCreated()->assertJsonPath('data.seat_selection', true);
    }
}
