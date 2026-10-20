<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\Booking;
use App\Models\Trip;
use App\Services\TripManagementService;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TripController extends Controller
{
    public function __construct(private readonly TripManagementService $trips, private readonly TripService $availability) {}

    public function index(Request $request)
    {
        $paginator = $this->filtered($request)->with(['bus', 'route', 'fares'])
            ->orderByDesc('travel_date')->orderBy('departure_time')
            ->paginate(20);

        $this->availability->withAvailability($paginator->getCollection());

        return TripResource::collection($paginator);
    }

    /** A station master only works on the routes assigned to them; admins on all. */
    private function scoped($query, Request $request)
    {
        $ids = $request->user()->accessibleRouteIds();

        return $ids === null ? $query : $query->whereIn('trips.route_id', $ids);
    }

    private function assertRoute(Request $request, int $routeId): void
    {
        abort_unless($request->user()->canAccessRoute($routeId), 403, 'That route is not assigned to you.');
    }

    /** Trips matching the page's filters: day, status, kind (scheduled | queue) and route. */
    private function filtered(Request $request, bool $withRoute = true)
    {
        return $this->scoped(Trip::query(), $request)
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('travel_date', $d))
            ->when($request->query('kind') === 'scheduled', fn ($q) => $q->where('status', Trip::SCHEDULED))
            ->when($request->query('kind') === 'queue', fn ($q) => $q->whereIn('status', Trip::QUEUE_STATUSES))
            ->when($withRoute && $request->query('route_id'), fn ($q, $r) => $q->where('route_id', $r));
    }

    /**
     * Numbers for the filter bar: how many trips are scheduled / in the queue that day, how many on each route,
     * and which active buses are free (not on an open trip that day).
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'kind' => ['nullable', 'in:scheduled,queue'], 'route_id' => ['nullable', 'integer']]);
        $date = $request->query('date');
        $onDay = fn ($q) => $this->scoped($date ? $q->whereDate('travel_date', $date) : $q, $request);

        $count = fn (array $statuses) => $onDay(Trip::query())->whereIn('status', $statuses)->count();
        $scheduled = $count([Trip::SCHEDULED]);
        $queue = $count(Trip::QUEUE_STATUSES);

        $routes = $this->filtered($request, false)->join('routes', 'routes.id', '=', 'trips.route_id')
            ->selectRaw('routes.id, routes.origin, routes.destination, count(*) as total')
            ->groupBy('routes.id', 'routes.origin', 'routes.destination')->orderBy('routes.origin')->orderBy('routes.destination')->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'route' => $r->origin.' → '.$r->destination, 'count' => (int) $r->total])->values();

        $busy = $onDay(Trip::query())->whereIn('status', [Trip::SCHEDULED, ...Trip::QUEUE_STATUSES])->pluck('bus_id');
        $free = \App\Models\Bus::where('status', 'active')->whereNotIn('id', $busy)->orderBy('name')->get(['id', 'name', 'registration_number', 'seat_count'])
            ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'registration_number' => $b->registration_number, 'seat_count' => $b->seat_count])->values();

        return response()->json(['data' => [
            'total' => $scheduled + $queue + $onDay(Trip::query())->whereNotIn('status', [Trip::SCHEDULED, ...Trip::QUEUE_STATUSES])->count(),
            'scheduled' => $scheduled,
            'queue' => $queue,
            'routes' => $routes,
            'free_buses' => $free,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        $this->assertRoute($request, (int) $data['route_id']);
        $trip = $this->trips->create($this->withoutMoneyFor($request, $data));

        return response()->json(['data' => new TripResource($trip->load(['bus', 'route', 'fares']))], 201);
    }

    public function update(Request $request, Trip $trip): JsonResponse
    {
        $this->assertRoute($request, $trip->route_id);
        $data = $request->validate($this->rules(false));
        if (isset($data['route_id'])) {
            $this->assertRoute($request, (int) $data['route_id']);
        }
        $trip = $this->trips->update($trip, $this->withoutMoneyFor($request, $data));

        return response()->json(['data' => new TripResource($trip->load(['bus', 'route', 'fares']))]);
    }

    public function destroy(Trip $trip): JsonResponse
    {
        $this->trips->delete($trip);

        return response()->json(['message' => 'Trip deleted.']);
    }

    /** The commission is an admin's call: a station master cannot set or change it. */
    private function withoutMoneyFor(Request $request, array $data): array
    {
        if (! $request->user()->isAdmin()) {
            unset($data['commission']);
        }

        return $data;
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'bus_id' => [$req, 'integer', Rule::exists('buses', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'route_id' => [$req, 'integer', Rule::exists('routes', 'id')->where('status', 'active')->whereNull('deleted_at')],
            'travel_date' => [$req, 'date_format:Y-m-d', ...($creating ? ['after_or_equal:today'] : [])],
            'departure_time' => [$req, 'date_format:H:i'],
            'price' => [$req, 'numeric', 'min:0', 'max:100000'],
            // Online commission per seat for this trip. Leave empty to use the route's.
            'commission' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // One trip, several destinations: a price for each destination (the start is fixed). Empty = one price for all.
            'fares' => ['sometimes', 'array', 'max:30'],
            'fares.*.place' => ['nullable', 'string', 'max:100'],
            'fares.*.departure_time' => ['nullable', 'date_format:H:i'], // many starts: when this pickup point leaves
            'fares.*.pickup_point_id' => ['nullable', 'integer'], // many starts: the route's pickup point the price is for
            'fares_by' => ['sometimes', Rule::in([Trip::FARES_BY_DESTINATION, Trip::FARES_BY_ORIGIN])],
            'fares.*.price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'status' => $creating ? ['prohibited'] : ['sometimes', Rule::in(Trip::STATUSES)],
        ];
    }
}
