<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Models\BusRoute;
use App\Models\Trip;
use App\Models\TripFare;
use App\Services\SeatHoldService;
use App\Services\TripService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function __construct(private readonly TripService $trips, private readonly SeatHoldService $holds) {}

    /** Active origins/destinations to power the search form. */
    public function locations(): JsonResponse
    {
        $routes = BusRoute::where('status', 'active')->get(['origin', 'destination']);

        // A bus can serve several destinations on one trip (Accra to Kintampo, also Techiman): those places are
        // offered in "To" too, for trips that are still open for booking.
        $open = fn ($by) => TripFare::query()
            ->whereHas('trip', fn ($q) => $q->where('fares_by', $by)->where('status', Trip::SCHEDULED)->whereDate('travel_date', '>=', today())
                ->whereHas('route', fn ($r) => $r->where('status', 'active')))
            ->pluck('destination');
        $unique = fn ($list) => $list->unique(fn ($p) => mb_strtolower($p))->sort()->values();

        // Which "To" goes with which "From": a route's own ends, and the places a bus serves on one trip
        // (Accra to Kintampo, also Techiman; or Techiman and Nsawam, both to Accra).
        $pairs = $routes->map(fn ($r) => ['from' => $r->origin, 'to' => $r->destination]);
        TripFare::query()
            ->whereHas('trip', fn ($q) => $q->where('status', Trip::SCHEDULED)->whereDate('travel_date', '>=', today())
                ->whereHas('route', fn ($r) => $r->where('status', 'active')))
            ->with('trip.route')->get()
            ->each(function (TripFare $f) use (&$pairs) {
                $route = $f->trip->route;
                $pairs->push($f->trip->pricedByOrigin()
                    ? ['from' => $f->destination, 'to' => $route->destination]
                    : ['from' => $route->origin, 'to' => $f->destination]);
            });

        return response()->json(['data' => [
            // Many starts, one destination (Techiman, Kintampo, Nsawam all to Accra): those starting places are offered in "From".
            'origins' => $unique($routes->pluck('origin')->merge($open(Trip::FARES_BY_ORIGIN))),
            'destinations' => $unique($routes->pluck('destination')->merge($open(Trip::FARES_BY_DESTINATION))),
            'pairs' => $pairs->unique(fn ($p) => mb_strtolower($p['from'].'|'.$p['to']))->sortBy(fn ($p) => mb_strtolower($p['from'].'|'.$p['to']))->values(),
        ]]);
    }

    /** What the customer's Contact us and Privacy pages show, as set by an admin under Settings. */
    public function site(): JsonResponse
    {
        $get = fn (string $key) => trim((string) \App\Models\Setting::get($key, '')) ?: null;

        return response()->json(['data' => [
            'contact' => [
                'intro' => $get('contact_intro'),
                'phone' => $get('support_phone'),
                'whatsapp' => $get('contact_whatsapp'),
                'email' => $get('contact_email'),
                'address' => $get('contact_address'),
                'hours' => $get('contact_hours'),
                'map_url' => $get('contact_map_url'),
                'map_embed' => $get('contact_map_embed'),
            ],
            'privacy_text' => $get('page_privacy'),
        ]]);
    }

    /** Ad settings for the customer site. Empty unless ads are on and a publisher id is saved. */
    public function ads(): JsonResponse
    {
        $on = \App\Models\Setting::get('ads_enabled', '0') === '1';
        $publisher = (string) \App\Models\Setting::get('ads_publisher_id', '');

        if (! $on || $publisher === '') {
            return response()->json(['data' => ['enabled' => false]]);
        }

        return response()->json(['data' => [
            'enabled' => true,
            'publisher_id' => $publisher,
            'slots' => [
                'home' => \App\Models\Setting::get('ads_slot_home', '') ?: null,
                'results' => \App\Models\Setting::get('ads_slot_results', '') ?: null,
                'confirmation' => \App\Models\Setting::get('ads_slot_confirmation', '') ?: null,
            ],
        ]]);
    }

    public function search(Request $request)
    {
        $data = $request->validate([
            'from' => ['nullable', 'string', 'max:100'],
            'to' => ['nullable', 'string', 'max:100'],
            // Leave the date out to browse everything that is open for booking.
            'date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ]);

        return TripResource::collection($this->trips->search($data['from'] ?? null, $data['to'] ?? null, $data['date'] ?? null));
    }

    public function show(Trip $trip): JsonResponse
    {
        $trip->loadMissing('route');
        abort_unless($trip->isOpenForSale(), 404, 'Trip not available.');

        $this->holds->expire($trip->id);
        $trip->load(['bus', 'fares', 'route.pickupPoints' => fn ($q) => $q->where('status', 'active')->orderBy('stop_order')->orderBy('name')]);
        $this->trips->withAvailability(collect([$trip]));

        return response()->json(['data' => [
            'trip' => new TripResource($trip),
            'seats' => $this->trips->seatMap($trip),
            'max_seats_per_booking' => config('busbooking.max_seats_per_booking'),
        ]]);
    }
}
