<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PickupPoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PickupPointController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $routeIds = $request->user()->accessibleRouteIds();
        $points = PickupPoint::with('route')
            ->when($routeIds !== null, fn ($q) => $q->whereIn('route_id', $routeIds))
            ->when($request->query('route_id'), fn ($q, $id) => $q->where('route_id', $id))
            ->orderBy('route_id')->orderBy('name')->get();

        return response()->json(['data' => $points]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id' => ['required', 'integer', Rule::exists('routes', 'id')->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'stop_order' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'is_main_station' => ['sometimes', 'boolean'],
        ]);

        abort_unless($request->user()->canAccessRoute((int) $data['route_id']), 403, 'That route is not assigned to you.');

        return response()->json(['data' => PickupPoint::create($data)->load('route')], 201);
    }

    /**
     * Several stops on one route in one go. They are numbered in the order given, after the route's existing stops,
     * so the list the admin types is the order the vehicle visits them.
     */
    public function storeMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'route_id' => ['required', 'integer', Rule::exists('routes', 'id')->whereNull('deleted_at')],
            'points' => ['required', 'array', 'min:1', 'max:30'],
            'points.*.name' => ['required', 'string', 'max:120'],
            'points.*.description' => ['nullable', 'string', 'max:255'],
            'points.*.is_main_station' => ['sometimes', 'boolean'],
        ]);
        abort_unless($request->user()->canAccessRoute((int) $data['route_id']), 403, 'That route is not assigned to you.');

        $created = DB::transaction(function () use ($data) {
            $order = (int) PickupPoint::where('route_id', $data['route_id'])->max('stop_order');

            // by reference: each stop takes the next number
            return collect($data['points'])->map(function ($p) use ($data, &$order) {
                return PickupPoint::create([
                    'route_id' => $data['route_id'],
                    'name' => trim($p['name']),
                    'description' => $p['description'] ?? null,
                    'is_main_station' => (bool) ($p['is_main_station'] ?? false),
                    'status' => 'active',
                    'stop_order' => min(99, ++$order),
                ])->load('route');
            });
        });

        return response()->json(['data' => $created->values()], 201);
    }

    public function update(Request $request, PickupPoint $pickupPoint): JsonResponse
    {
        abort_unless($request->user()->canAccessRoute($pickupPoint->route_id), 403, 'That route is not assigned to you.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'stop_order' => ['sometimes', 'integer', 'min:1', 'max:99'],
            'is_main_station' => ['sometimes', 'boolean'],
        ]);
        $pickupPoint->update($data);

        return response()->json(['data' => $pickupPoint->load('route')]);
    }

    public function destroy(PickupPoint $pickupPoint): JsonResponse
    {
        $pickupPoint->delete(); // soft delete: existing bookings keep their pickup name

        return response()->json(['message' => 'Pickup point deleted.']);
    }
}
