<?php

namespace App\Http\Controllers\Api\Station;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\BusRoute;
use App\Models\PickupPoint;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Short lists for the forms a station master uses when an admin has let them create buses, pickup points or trips.
 * They only see their own routes, and none of the money settings (commission, station dues).
 */
class ManageController extends Controller
{
    public function routes(Request $request): JsonResponse
    {
        $ids = $request->user()->accessibleRouteIds();

        $routes = BusRoute::query()->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->orderBy('origin')->orderBy('destination')->get(['id', 'origin', 'destination', 'status', 'mode']);

        return response()->json(['data' => $routes]);
    }

    /** The pickup points of their routes, for pricing a trip by where passengers board. */
    public function stops(Request $request): JsonResponse
    {
        $ids = $request->user()->accessibleRouteIds();

        return response()->json(['data' => PickupPoint::query()->when($ids !== null, fn ($q) => $q->whereIn('route_id', $ids))
            ->orderBy('route_id')->orderBy('stop_order')->get(['id', 'route_id', 'name', 'description', 'status', 'stop_order'])]);
    }

    public function vehicleTypes(): JsonResponse
    {
        return response()->json(['data' => VehicleCategory::orderBy('name')->get(['id', 'name', 'seat_selection'])]);
    }

    public function buses(): JsonResponse
    {
        return response()->json(['data' => Bus::orderBy('name')->get(['id', 'name', 'registration_number', 'seat_count', 'status', 'category_id'])]);
    }
}
