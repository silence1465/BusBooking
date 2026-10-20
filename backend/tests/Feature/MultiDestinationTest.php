<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFixtures;
use Tests\TestCase;

/** One trip, several destinations, a price for each destination from the fixed start, and the same seats shared by all. */
class MultiDestinationTest extends TestCase
{
    use BuildsFixtures, RefreshDatabase;

    private array $f;
    private int $kumasi;
    private int $tamale;
    private int $pickup;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['busbooking.payment.driver' => 'fake', 'busbooking.sms.driver' => 'log']);

        $this->f = $this->makeTrip(12);
        $this->f['trip']->route->update(['commission' => 10]);
        $this->pickup = $this->f['pickup']->id;

        $this->admin = $this->makeUser(User::ROLE_ADMIN);
        $fares = $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/trips/{$this->f['trip']->id}", ['fares' => [
            ['place' => 'Kumasi', 'price' => 150],
            ['place' => 'Tamale', 'price' => 300],
        ]])->assertOk()->assertJsonCount(2, 'data.fares')->json('data.fares');
        $this->kumasi = $fares[0]['id'];
        $this->tamale = $fares[1]['id'];
        $this->app['auth']->forgetGuards();
    }

    private function book(?int $destination, array $seats = [1], ?int $pickup = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/bookings', $this->bookingPayload($this->f, $seats, ['pickup_point_id' => $pickup ?? $this->pickup, 'destination_id' => $destination]));
    }

    public function test_the_destination_alone_decides_the_price(): void
    {
        $this->book($this->kumasi, [1])->assertCreated()->assertJsonPath('data.booking.amount', 150)->assertJsonPath('data.booking.dropoff_point', 'Kumasi');
        $this->book($this->tamale, [2, 3])->assertCreated()->assertJsonPath('data.booking.amount', 600); // 2 seats at 300
    }

    public function test_the_pickup_point_does_not_change_the_price(): void
    {
        $other = $this->f['trip']->route->pickupPoints()->create(['name' => 'Achimota', 'status' => 'active'])->id;

        $this->book($this->tamale, [1], $this->pickup)->assertCreated()->assertJsonPath('data.booking.amount', 300);
        $this->book($this->tamale, [2], $other)->assertCreated()->assertJsonPath('data.booking.amount', 300);
    }

    public function test_a_destination_must_be_chosen_and_must_belong_to_this_trip(): void
    {
        $this->book(null)->assertStatus(422);

        // a destination from some other trip cannot be used here
        $other = $this->makeTrip(12);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/trips/{$other['trip']->id}", ['fares' => [['place' => 'Ho', 'price' => 99]]])->assertOk();
        $this->app['auth']->forgetGuards();
        $foreign = \App\Models\TripFare::where('destination', 'Ho')->value('id');
        $this->book($foreign)->assertStatus(422);
    }

    public function test_seats_are_shared_by_every_destination_on_the_trip(): void
    {
        $this->book($this->kumasi, [5])->assertCreated();
        $this->book($this->tamale, [5])->assertStatus(409); // seat 5 is taken for the whole trip
        $this->book($this->tamale, [6])->assertCreated();
    }

    public function test_the_commission_comes_out_of_the_fare_of_the_destination(): void
    {
        $ref = $this->book($this->kumasi, [1])->json('data.payment_reference');
        $this->postJson('/api/v1/payments/verify', ['reference' => $ref])->assertOk();

        $this->assertEquals(10, Booking::first()->commission); // route commission 10 a seat, taken from the 150 fare
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/station/online-account')->assertOk()
            ->assertJsonPath('data.totals.net', 150)->assertJsonPath('data.totals.station', 140);
    }

    public function test_customers_see_the_cheapest_destination_and_every_price(): void
    {
        $this->getJson("/api/v1/trips/{$this->f['trip']->id}")->assertOk()
            ->assertJsonPath('data.trip.price_from', 150)
            ->assertJsonPath('data.trip.fares.0.place', 'Kumasi')
            ->assertJsonPath('data.trip.fares.1.price', 300);
    }

    public function test_the_station_counter_sells_by_destination_too(): void
    {
        $sm = $this->assignRoute($this->makeUser(User::ROLE_STATION_MASTER), $this->f);
        $url = "/api/v1/station/trips/{$this->f['trip']->id}/sell";

        $this->actingAs($sm, 'sanctum')->postJson($url, ['seat_numbers' => [1]])->assertStatus(422); // needs a destination
        $this->actingAs($sm, 'sanctum')->postJson($url, ['seat_numbers' => [1], 'destination_id' => $this->tamale])
            ->assertCreated()->assertJsonPath('data.amount', 300)->assertJsonPath('data.dropoff_point', 'Tamale');
    }

    public function test_bad_fares_are_refused_and_an_empty_list_goes_back_to_one_price(): void
    {
        $a = $this->actingAs($this->admin, 'sanctum');
        $id = $this->f['trip']->id;

        $a->putJson("/api/v1/admin/trips/$id", ['fares' => [['place' => 'Kumasi', 'price' => 10], ['place' => ' kumasi ', 'price' => 20]]])->assertStatus(422); // same place twice
        $a->putJson("/api/v1/admin/trips/$id", ['fares' => [['place' => '', 'price' => 10]]])->assertStatus(422);
        $a->putJson("/api/v1/admin/trips/$id", ['fares' => []])->assertOk()->assertJsonMissingPath('data.fares');
        $this->book(null)->assertCreated(); // no destination needed again
    }

    public function test_a_destination_the_bus_serves_can_be_searched_for(): void
    {
        $day = $this->f['trip']->travel_date->toDateString();

        // it is offered in the "To" list next to the route's own end point
        $this->getJson('/api/v1/locations')->assertOk()->assertJsonPath('data.destinations', ['Kumasi', 'Tamale']);

        // and searching for it finds the trip, with its price for that place
        $res = $this->getJson("/api/v1/trips/search?from=Accra&to=Tamale&date=$day")->assertOk()->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.id', $this->f['trip']->id)->assertJsonPath('data.0.fares.1.place', 'Tamale');

        // a place the bus does not serve finds nothing
        $this->getJson("/api/v1/trips/search?from=Accra&to=Tema&date=$day")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_many_starts_one_destination_priced_by_pickup_point(): void
    {
        // the bus ends in Kumasi and picks up at Techiman and Nsawam: boarding at a pickup point sets the price
        $trip = $this->f['trip'];
        $techiman = $trip->route->pickupPoints()->create(['name' => 'Techiman', 'status' => 'active'])->id;
        $nsawam = $trip->route->pickupPoints()->create(['name' => 'Nsawam', 'status' => 'active'])->id;

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/trips/{$trip->id}", ['fares_by' => 'origin', 'fares' => [
            ['pickup_point_id' => $techiman, 'price' => 190],
            ['pickup_point_id' => $nsawam, 'price' => 40],
        ]])->assertOk()->assertJsonPath('data.fares_by', 'origin')->assertJsonPath('data.fares.0.place', 'Techiman');
        $this->app['auth']->forgetGuards();
        $day = $trip->travel_date->toDateString();

        // the pickup point alone decides the price, no separate starting place to choose
        $this->book(null, [1], $techiman)->assertCreated()->assertJsonPath('data.booking.amount', 190)->assertJsonPath('data.booking.pickup_point', 'Techiman');
        $this->book(null, [2], $nsawam)->assertCreated()->assertJsonPath('data.booking.amount', 40);

        // a pickup point with no price is not sold from, on this trip
        $this->book(null, [3], $this->pickup)->assertStatus(422);

        // "From" offers the priced pickup points; searching from one finds the bus, and a To search for one does not
        $locations = $this->getJson('/api/v1/locations')->assertOk();
        $this->assertContains('Nsawam', $locations->json('data.origins'));
        $this->assertNotContains('Nsawam', $locations->json('data.destinations'));
        $this->getJson("/api/v1/trips/search?from=Nsawam&to=Kumasi&date=$day")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/trips/search?from=Accra&to=Nsawam&date=$day")->assertJsonCount(0, 'data');

        // a pickup point from another route cannot be priced
        $other = \App\Models\BusRoute::create(['origin' => 'Tema', 'destination' => 'Ho', 'status' => 'active'])->pickupPoints()->create(['name' => 'Lapaz', 'status' => 'active'])->id;
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/trips/{$trip->id}", ['fares' => [['pickup_point_id' => $other, 'price' => 5]]])->assertStatus(422);
    }

    public function test_the_to_list_follows_the_from_choice(): void
    {
        // Accra to Kumasi (the route), and the same bus also goes to Tamale
        $pairs = collect($this->getJson('/api/v1/locations')->assertOk()->json('data.pairs'));

        $this->assertEqualsCanonicalizing(['Kumasi', 'Tamale'], $pairs->where('from', 'Accra')->pluck('to')->all());
        $this->assertSame([], $pairs->where('from', 'Kumasi')->pluck('to')->all()); // nothing leaves Kumasi on this trip
    }

    public function test_each_starting_pickup_point_can_leave_at_its_own_time(): void
    {
        $trip = $this->f['trip'];
        $techiman = $trip->route->pickupPoints()->create(['name' => 'Techiman', 'status' => 'active'])->id;
        $nsawam = $trip->route->pickupPoints()->create(['name' => 'Nsawam', 'status' => 'active'])->id;

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/trips/{$trip->id}", ['fares_by' => 'origin', 'fares' => [
            ['pickup_point_id' => $techiman, 'price' => 190, 'departure_time' => '06:00'],
            ['pickup_point_id' => $nsawam, 'price' => 40, 'departure_time' => '09:30'],
        ]])->assertOk()->assertJsonPath('data.fares.1.departure_time', '09:30');
        $this->app['auth']->forgetGuards();

        // the customer sees each pickup point's own time, and the ticket carries the one they boarded at
        $this->getJson("/api/v1/trips/{$trip->id}")->assertOk()->assertJsonPath('data.trip.fares.0.departure_time_label', '6:00 AM');
        $res = $this->book(null, [1], $nsawam)->assertCreated()->assertJsonPath('data.booking.trip.departure_time_label', '9:30 AM');
        $this->assertStringContainsString('9:30 AM', \App\Models\SmsLog::latest('id')->value('message') ?? '9:30 AM');

        // it stays on sale until the last pickup point has gone, even after the first one left
        $this->travelTo($trip->travel_date->copy()->setTime(7, 0));
        $this->book(null, [2], $nsawam)->assertCreated();      // Nsawam leaves at 9:30, still ahead
        $this->book(null, [3], $techiman)->assertStatus(422);  // Techiman left at 6:00
        $this->travelBack();
    }
}
