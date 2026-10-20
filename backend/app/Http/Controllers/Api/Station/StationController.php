<?php

namespace App\Http\Controllers\Api\Station;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\TripResource;
use App\Models\Booking;
use App\Models\BookingSeat;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\Payment;
use App\Models\Trip;
use App\Models\User;
use App\Services\BoardingService;
use App\Services\BookingService;
use App\Services\SeatHoldService;
use App\Services\StationService;
use App\Services\TripService;
use App\Support\BookingCode;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StationController extends Controller
{
    private const WITH = ['trip.route', 'trip.bus', 'pickupPoint', 'seats', 'payment', 'confirmedBy'];

    public function __construct(private readonly StationService $station) {}

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'trip_id' => ['nullable', 'integer'], // the trip being boarded, if the station master picked one
        ]);

        return $this->respond($this->station->verify($data['code'], $request->user(), $data['trip_id'] ?? null));
    }

    public function confirmArrival(Request $request, string $code): JsonResponse
    {
        $data = $request->validate(['trip_id' => ['nullable', 'integer']]);
        $booking = $this->station->confirmArrival($code, $request->user(), $data['trip_id'] ?? null);

        return response()->json(['data' => [
            'status' => 'confirmed',
            'message' => 'Passenger arrival confirmed.',
            'booking' => new BookingResource($booking),
        ]]);
    }

    /** Find upcoming bookings by (part of) a passenger phone number. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $digits = Phone::digits($data['phone']);
        abort_if(strlen($digits) < 7, 422, 'Enter at least 7 digits of the phone number.');

        $bookings = Booking::with(self::WITH)
            ->where('phone', 'like', '%'.substr($digits, -9).'%')
            ->whereIn('status', [Booking::PENDING, Booking::CONFIRMED])
            ->whereHas('trip', fn ($q) => $this->scopeToUserRoutes($q, $request)->whereDate('travel_date', '>=', today())->where('status', '!=', Trip::CANCELLED))
            ->get()
            ->filter(fn (Booking $b) => $b->status !== Booking::PENDING || $b->expires_at?->isFuture())
            ->sortBy(fn (Booking $b) => $b->trip->departsAt()?->timestamp ?? PHP_INT_MAX)
            ->values();

        return response()->json(['data' => BookingResource::collection($bookings)]);
    }

    public function trips(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $request->query('date', today()->toDateString());
        $active = [Booking::CONFIRMED, Booking::COMPLETED];
        Trip::carryQueueOver(); // yesterday's waiting vehicles are still in the queue today

        $trips = $this->scopeToUserRoutes(Trip::with(['bus.category', 'route']), $request)
            ->when($request->user()->role === 'driver' && $request->user()->bus_id, fn ($q) => $q->where('bus_id', $request->user()->bus_id))
            ->whereDate('travel_date', $date)->where('status', '!=', Trip::CANCELLED)
            // Timetabled trips by time, then fill-and-go vehicles in the order they were opened.
            ->orderByRaw('departure_time is null')->orderBy('departure_time')->orderBy('queue_position')->orderBy('opened_at')->get();

        $seatsBooked = BookingSeat::query()
            ->join('bookings', 'bookings.id', '=', 'booking_seats.booking_id')
            ->whereIn('bookings.trip_id', $trips->pluck('id'))->whereIn('bookings.status', $active)
            ->whereNull('bookings.deleted_at')
            ->selectRaw('bookings.trip_id, count(*) as total, sum(case when bookings.confirmed_at is not null then 1 else 0 end) as arrived')
            ->groupBy('bookings.trip_id')->get()->keyBy('trip_id');

        return response()->json(['data' => $trips->map(fn (Trip $t) => [
            'id' => $t->id,
            'route_id' => $t->route_id,
            'route' => $t->route->origin.' → '.$t->route->destination,
            'bus' => $t->bus->name,
            'bus_registration' => $t->bus->registration_number,
            'travel_date' => $t->travel_date->format('Y-m-d'),
            'departure_time' => $t->departure_time ? substr($t->departure_time, 0, 5) : null,
            'departure_time_label' => $t->timeLabel(),
            'fill_and_go' => $t->route->mode === BusRoute::FILL_AND_GO,
            'status' => $t->status,
            'queue_position' => $t->queue_position,
            'station_due' => $t->station_due !== null ? (float) $t->station_due : null,
            'due_to_collect' => (float) $t->bus->stationDue(), // what this vehicle pays when it departs
            'due_paid' => $t->due_paid_at !== null,
            'total_seats' => $t->bus->seat_count,
            'booked_seats' => (int) ($seatsBooked[$t->id]->total ?? 0),
            'confirmed_arrivals' => (int) ($seatsBooked[$t->id]->arrived ?? 0),
        ])->values()]);
    }

    /**
     * The trip a driver is on right now: the vehicle of their linked bus that is on the road (or, failing
     * that, still boarding) today. Lets the driver's phone open straight to check-in.
     */
    public function myVehicle(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role !== User::ROLE_DRIVER || ! $user->bus_id) {
            return response()->json(['data' => ['linked' => false, 'bus' => null, 'trip' => null]]);
        }

        Trip::carryQueueOver();
        $trip = Trip::with(['bus', 'route'])->where('bus_id', $user->bus_id)
            ->whereDate('travel_date', today())->whereIn('status', [Trip::DEPARTED, Trip::BOARDING])
            ->orderByRaw("case status when 'departed' then 0 else 1 end")->orderByDesc('departed_at')->orderByDesc('opened_at')->first();

        return response()->json(['data' => [
            'linked' => true,
            'bus' => $user->bus->only('id', 'name', 'registration_number'),
            'trip' => $trip ? [
                'id' => $trip->id,
                'route' => $trip->route->origin.' → '.$trip->route->destination,
                'status' => $trip->status,
                'departure_time_label' => $trip->timeLabel(),
            ] : null,
        ]]);
    }

    /** Seat map plus what is needed to sell seats at the counter. */
    public function seats(Request $request, Trip $trip, TripService $trips, SeatHoldService $holds): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        abort_unless($trip->isOpenForCounterSale(), 404, 'Trip is not open for sales.');

        $holds->expire($trip->id);
        $trip->load(['bus', 'fares', 'route.pickupPoints' => fn ($q) => $q->where('status', 'active')->orderBy('stop_order')->orderBy('name')]);
        $trips->withAvailability(collect([$trip]));

        return response()->json(['data' => [
            'trip' => new TripResource($trip),
            'seats' => $trips->seatMap($trip),
        ]]);
    }

    public function sell(Request $request, Trip $trip, BookingService $bookings): JsonResponse
    {
        $this->authorizeTrip($request, $trip);

        $data = $request->validate([
            'seat_numbers' => ['required_without:seat_count', 'array', 'min:1', 'max:'.config('busbooking.max_seats_per_booking')],
            'seat_numbers.*' => ['required', 'integer', 'min:1', 'distinct'],
            'seat_count' => ['required_without:seat_numbers', 'integer', 'min:1', 'max:'.config('busbooking.max_seats_per_booking')],
            'passenger_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\s\-]{9,16}$/'],
            'pickup_point_id' => ['nullable', 'integer'],
            'destination_id' => ['nullable', 'integer'],
        ]);

        $booking = $bookings->sellAtStation($trip, $data, $request->user());

        return response()->json(['data' => new BookingResource($booking->load(self::WITH))], 201);
    }

    // ------------------------------------------------------------ fill-and-go vehicles

    /** Fill-and-go routes this user works on, and the buses that can be opened for boarding. */
    public function boardingSetup(Request $request): JsonResponse
    {
        $routes = $this->scopeToUserRoutesById(BusRoute::query(), $request)
            ->where('mode', BusRoute::FILL_AND_GO)->where('status', 'active')->orderBy('origin')->get();

        $busy = Trip::where('status', Trip::BOARDING)->pluck('bus_id');
        $buses = Bus::where('status', 'active')->whereNotIn('id', $busy)->orderBy('name')->get(['id', 'name', 'registration_number', 'seat_count']);

        return response()->json(['data' => [
            'routes' => $routes->map(fn (BusRoute $r) => [
                'id' => $r->id,
                'label' => $r->origin.' → '.$r->destination,
                'fare' => $r->fare !== null ? (float) $r->fare : null,
            ])->values(),
            'buses' => $buses,
        ]]);
    }

    public function openBoarding(Request $request, BusRoute $route, BoardingService $boarding): JsonResponse
    {
        abort_unless($request->user()->canAccessRoute($route->id), 403, 'You are not assigned to this route.');
        $data = $request->validate(['bus_id' => ['required', 'integer', Rule::exists('buses', 'id')->whereNull('deleted_at')]]);

        $trip = $boarding->openBoarding($route, Bus::findOrFail($data['bus_id']));

        return response()->json(['data' => new TripResource($trip->load(['bus', 'route']))], 201);
    }

    public function dispatch(Request $request, Trip $trip, BoardingService $boarding): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $data = $request->validate([
            'leave_behind' => ['sometimes', 'boolean'],
            'due_collected' => ['sometimes', 'boolean'], // the station master took the station due in cash
        ]);

        $trip = $boarding->dispatch($trip, (bool) ($data['leave_behind'] ?? false), $request->user(), (bool) ($data['due_collected'] ?? false));

        return response()->json(['data' => [
            'trip' => new TripResource($trip->load(['bus', 'route'])),
            'station_due' => (float) $trip->station_due,
            'due_paid' => $trip->due_paid_at !== null,
        ]]);
    }

    /** Rearrange the queue: up | down | front (only while the front vehicle is empty) | remove. */
    public function queue(Request $request, Trip $trip, BoardingService $boarding): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $data = $request->validate(['action' => ['required', Rule::in(['up', 'down', 'front', 'remove'])]]);

        match ($data['action']) {
            'up', 'down' => $boarding->move($trip, $data['action']),
            'front' => $boarding->makeFront($trip),
            'remove' => $boarding->removeFromQueue($trip),
        };

        return response()->json(['message' => 'Queue updated.']);
    }

    /** Record that the station due of a departed vehicle was collected (or undo that). */
    public function due(Request $request, Trip $trip, BoardingService $boarding): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $data = $request->validate(['paid' => ['required', 'boolean']]);

        $trip = $boarding->setDuePaid($trip, (bool) $data['paid'], $request->user());

        return response()->json(['data' => ['due_paid' => $trip->due_paid_at !== null]]);
    }

    /**
     * Every vehicle that left on a day, with passengers and the station due, plus totals.
     * Station masters see their own routes; admins see everything.
     */
    public function departures(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $request->query('date', today()->toDateString());

        $trips = $this->scopeToUserRoutes(Trip::with(['bus.category', 'route', 'dueCollector']), $request)
            ->whereIn('status', [Trip::DEPARTED, Trip::COMPLETED])
            ->where(fn ($q) => $q->whereDate('departed_at', $date)
                ->orWhere(fn ($w) => $w->whereNull('departed_at')->whereDate('travel_date', $date)))
            ->orderBy('departed_at')->orderBy('departure_time')->get();

        $passengers = BookingSeat::query()
            ->join('bookings', 'bookings.id', '=', 'booking_seats.booking_id')
            ->whereIn('bookings.trip_id', $trips->pluck('id'))
            ->whereIn('bookings.status', [Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW])
            ->whereNull('bookings.deleted_at')
            ->selectRaw('bookings.trip_id, count(*) as total')->groupBy('bookings.trip_id')->pluck('total', 'trip_id');

        $rows = $trips->map(function (Trip $t) use ($passengers) {
            $due = $t->station_due !== null ? (float) $t->station_due : null;

            return [
                'id' => $t->id,
                'departed_at' => ($t->departed_at ?? $t->departsAt())?->toIso8601String(),
                'route' => $t->route->origin.' → '.$t->route->destination,
                'bus' => $t->bus->name,
                'registration' => $t->bus->registration_number,
                'category' => $t->bus->category?->name ?? 'No category',
                'seat_count' => $t->bus->seat_count,
                'passengers' => (int) ($passengers[$t->id] ?? 0),
                'station_due' => $due,
                'due_status' => $due === null ? 'not_recorded' : ($due <= 0 ? 'none' : ($t->due_paid_at ? 'paid' : 'unpaid')),
                'due_paid_at' => $t->due_paid_at?->toIso8601String(),
                'collected_by' => $t->dueCollector?->name,
            ];
        })->values();

        $owed = $rows->where('station_due', '>', 0);

        return response()->json(['data' => [
            'date' => $date,
            'rows' => $rows,
            'totals' => [
                'vehicles' => $rows->count(),
                'passengers' => $rows->sum('passengers'),
                'dues' => (float) $owed->sum('station_due'),
                'collected' => (float) $owed->where('due_status', 'paid')->sum('station_due'),
                'outstanding' => (float) $owed->where('due_status', 'unpaid')->sum('station_due'),
            ],
            'currency' => config('busbooking.currency'),
        ]]);
    }

    /**
     * Money taken online (Paystack), by route, for a range of days. Counted by the day it was paid.
     * Sales made at the station counter are not included. Station masters see their own routes; admins see all.
     */
    public function onlineAccount(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $from = $data['from'] ?? today()->toDateString();
        $to = $data['to'] ?? $from;

        $query = Payment::query()
            ->join('bookings', 'bookings.id', '=', 'payments.booking_id')
            ->join('trips', 'trips.id', '=', 'bookings.trip_id')
            ->join('routes', 'routes.id', '=', 'trips.route_id')
            // seats per booking, joined as a derived table so the grouped query stays valid under ONLY_FULL_GROUP_BY
            ->leftJoinSub(BookingSeat::query()->selectRaw('booking_id, count(*) as seats')->groupBy('booking_id'), 'seat_totals', 'seat_totals.booking_id', '=', 'bookings.id')
            ->where('bookings.source', 'online')
            ->whereIn('payments.status', [Payment::PAID, Payment::REFUNDED])
            ->whereBetween('payments.paid_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->whereNull('payments.deleted_at');

        if (($routeIds = $request->user()->accessibleRouteIds()) !== null) {
            $query->whereIn('routes.id', $routeIds);
        }

        // Per car: the same sums, one line per trip, so a route can be opened to see which vehicle took what.
        $vehicles = (clone $query)
            ->join('buses', 'buses.id', '=', 'trips.bus_id')
            ->selectRaw("trips.route_id, trips.id as trip_id, trips.travel_date, trips.departure_time, trips.departed_at, trips.status, buses.name as bus, buses.registration_number as registration, count(*) as bookings,
                coalesce(sum(seat_totals.seats), 0) as seats,
                coalesce(sum(case when payments.status = 'paid' then payments.amount else 0 end), 0) as net,
                coalesce(sum(case when payments.status = 'refunded' then payments.amount else 0 end), 0) as refunded,
                coalesce(sum(case when payments.status = 'paid' then bookings.commission else 0 end), 0) as commission")
            ->groupBy('trips.route_id', 'trips.id', 'trips.travel_date', 'trips.departure_time', 'trips.departed_at', 'trips.status', 'buses.name', 'buses.registration_number')
            ->orderBy('trips.travel_date')->orderBy('trips.departure_time')->orderBy('trips.id')
            ->get()
            ->groupBy('route_id')
            ->map(fn ($list) => $list->map(fn ($v) => [
                'trip_id' => (int) $v->trip_id,
                'travel_date' => substr((string) $v->travel_date, 0, 10),
                'departure_time' => $v->departure_time ? substr($v->departure_time, 0, 5) : null,
                'departed_at' => $v->departed_at ? Carbon::parse($v->departed_at)->toIso8601String() : null,
                'status' => $v->status,
                'bus' => $v->bus,
                'registration' => $v->registration,
                'bookings' => (int) $v->bookings,
                'seats' => (int) $v->seats,
                'gross' => round((float) $v->net + (float) $v->refunded, 2),
                'refunded' => round((float) $v->refunded, 2),
                'net' => round((float) $v->net, 2),
                'commission' => round((float) $v->commission, 2),
                'station' => round((float) $v->net - (float) $v->commission, 2),
            ])->values());

        $rows = $query
            ->selectRaw("routes.id, routes.origin, routes.destination, count(*) as bookings,
                coalesce(sum(seat_totals.seats), 0) as seats,
                coalesce(sum(case when payments.status = 'paid' then payments.amount else 0 end), 0) as net,
                coalesce(sum(case when payments.status = 'refunded' then payments.amount else 0 end), 0) as refunded,
                coalesce(sum(case when payments.status = 'paid' then bookings.commission else 0 end), 0) as commission")
            ->groupBy('routes.id', 'routes.origin', 'routes.destination')
            ->orderBy('routes.origin')->orderBy('routes.destination')
            ->get()
            ->map(fn ($r) => [
                'route_id' => (int) $r->id,
                'vehicles' => $vehicles->get($r->id, collect())->values(),
                'route' => $r->origin.' → '.$r->destination,
                'bookings' => (int) $r->bookings,
                'seats' => (int) $r->seats,
                'gross' => round((float) $r->net + (float) $r->refunded, 2),
                'refunded' => round((float) $r->refunded, 2),
                'net' => round((float) $r->net, 2),
                'commission' => round((float) $r->commission, 2), // the online platform's share
                'station' => round((float) $r->net - (float) $r->commission, 2), // what the station gets
            ])->values();

        // Only an admin sees the commission. Everyone else gets the station's share alone: the sales totals are
        // left out too, because sales minus the station's share would give the commission away.
        $full = $request->user()->isAdmin();
        $hide = fn (array $line) => $full ? $line : array_diff_key($line, array_flip(['gross', 'net', 'commission']));
        $rows = $rows->map(fn (array $r) => $hide(array_merge($r, ['vehicles' => collect($r['vehicles'])->map($hide)->values()])))->values();

        return response()->json(['data' => [
            'from' => $from,
            'to' => $to,
            'show_commission' => $full,
            'rows' => $rows,
            'totals' => $hide([
                'routes' => $rows->count(),
                'bookings' => $rows->sum('bookings'),
                'seats' => $rows->sum('seats'),
                'gross' => round($rows->sum('gross'), 2),
                'refunded' => round($rows->sum('refunded'), 2),
                'net' => round($rows->sum('net'), 2),
                'commission' => round($rows->sum('commission'), 2),
                'station' => round($rows->sum('station'), 2),
            ]),
            'currency' => config('busbooking.currency'),
        ]]);
    }

    /** Passenger did not turn up: moves to the next vehicle (main station) or is forfeited (pickup point). */
    public function noShow(Request $request, string $code, BoardingService $boarding): JsonResponse
    {
        $booking = Booking::with('trip')->where('code', BookingCode::normalize($code))->firstOrFail();
        abort_unless($request->user()->canWorkOnTrip($booking->trip), 403, 'This booking is not on a trip assigned to you.');

        $result = $boarding->markNoShow($booking, $request->user());

        return response()->json(['data' => [
            'outcome' => $result['outcome'],
            'booking' => new BookingResource($result['booking']->load(self::WITH)),
        ]]);
    }

    // ------------------------------------------------------------ passenger list

    public function manifest(Request $request, Trip $trip): JsonResponse
    {
        $this->authorizeTrip($request, $trip);
        $q = trim((string) $request->query('q', ''));
        $trip->load(['bus', 'route']);

        $bookings = Booking::with(self::WITH)
            ->where('trip_id', $trip->id)
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED, Booking::NO_SHOW])
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('code', 'like', '%'.strtoupper($q).'%')->orWhere('passenger_name', 'like', "%$q%");
                if (strlen(Phone::digits($q)) >= 4) {
                    $w->orWhere('phone', 'like', '%'.Phone::digits($q).'%');
                }
            }))
            ->get()
            ->sortBy(fn (Booking $b) => $b->seats->min('seat_number'))
            ->values();

        return response()->json(['data' => [
            'trip' => [
                'id' => $trip->id,
                'route' => $trip->route->origin.' → '.$trip->route->destination,
                'bus' => $trip->bus->name,
                'travel_date' => $trip->travel_date->format('Y-m-d'),
                'departure_time_label' => $trip->timeLabel(),
                'status' => $trip->status,
                'fill_and_go' => $trip->route->mode === BusRoute::FILL_AND_GO,
                'seat_count' => $trip->bus->seat_count,
                'seat_selection' => $trip->bus->allowsSeatSelection(),
                'full_at' => $trip->full_at?->toIso8601String(),
                'grace_minutes' => config('busbooking.boarding.grace_minutes'),
            ],
            'bookings' => BookingResource::collection($bookings),
        ]]);
    }

    /** Station masters and drivers only see trips on the routes an admin assigned to them. */
    private function scopeToUserRoutes($query, Request $request)
    {
        $routeIds = $request->user()->accessibleRouteIds();

        return $routeIds === null ? $query : $query->whereIn('route_id', $routeIds);
    }

    private function scopeToUserRoutesById($query, Request $request)
    {
        $routeIds = $request->user()->accessibleRouteIds();

        return $routeIds === null ? $query : $query->whereIn('id', $routeIds);
    }

    private function authorizeTrip(Request $request, Trip $trip): void
    {
        abort_unless($request->user()->canWorkOnTrip($trip), 403, 'This trip is not assigned to you.');
    }

    private function respond(array $state): JsonResponse
    {
        return response()->json(['data' => [
            'status' => $state['status'],
            'message' => $state['message'],
            'warning' => $state['warning'],
            'ticket_trip' => $state['ticket_trip'],
            'booking' => $state['booking'] ? new BookingResource($state['booking']) : null,
        ]]);
    }
}
