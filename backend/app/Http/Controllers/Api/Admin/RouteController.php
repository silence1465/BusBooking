<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\BusRoute;
use App\Models\Trip;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouteController extends Controller
{
    private function rules(): array
    {
        return [
            'origin' => ['required', 'string', 'max:100'],
            'destination' => ['required', 'string', 'max:100', 'different:origin'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'mode' => ['sometimes', Rule::in(['scheduled', 'fill_and_go'])],
            // Price per seat for fill-and-go vehicles (scheduled trips set their own price).
            'fare' => ['nullable', 'numeric', 'min:0', 'max:100000', 'required_if:mode,fill_and_go'],
            // Online commission per seat: the part of an online sale that goes to the online platform.
            'commission' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // Optional different amount per vehicle type: { vehicle_category_id: amount }. Blank = use the route's.
            'category_commissions' => ['sometimes', 'array'],
            'category_commissions.*' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    public function index(): JsonResponse
    {
        $routes = BusRoute::withCount('pickupPoints')->with('categoryCommissions')->orderBy('origin')->orderBy('destination')->get();

        return response()->json(['data' => $routes->map(fn (BusRoute $r) => $this->present($r))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $route = BusRoute::create($this->attributes($data));
        $this->saveCategoryCommissions($route, $data);

        return response()->json(['data' => $this->present($route)], 201);
    }

    public function update(Request $request, BusRoute $route): JsonResponse
    {
        $data = $request->validate($this->rules());
        $route->update($this->attributes($data));
        $this->saveCategoryCommissions($route, $data);

        return response()->json(['data' => $this->present($route)]);
    }

    private function attributes(array $data): array
    {
        unset($data['category_commissions']);
        if (array_key_exists('commission', $data)) {
            $data['commission'] = $data['commission'] ?? 0;
        }

        return $data;
    }

    private function saveCategoryCommissions(BusRoute $route, array $data): void
    {
        if (! array_key_exists('category_commissions', $data)) {
            return;
        }

        $valid = VehicleCategory::pluck('id')->all();
        $route->categoryCommissions()->delete();
        foreach ($data['category_commissions'] as $categoryId => $amount) {
            if ($amount !== null && $amount !== '' && in_array((int) $categoryId, $valid, true)) {
                $route->categoryCommissions()->create(['vehicle_category_id' => (int) $categoryId, 'commission' => $amount]);
            }
        }
    }

    private function present(BusRoute $route): array
    {
        $route->load('categoryCommissions');

        return array_merge($route->withoutRelations()->toArray(), [
            'commission' => (float) $route->commission,
            'category_commissions' => (object) $route->categoryCommissions->mapWithKeys(fn ($c) => [$c->vehicle_category_id => (float) $c->commission])->all(),
        ]);
    }

    public function destroy(BusRoute $route): JsonResponse
    {
        if ($route->trips()->whereIn('status', [Trip::SCHEDULED, ...Trip::QUEUE_STATUSES])->exists()) {
            throw new BookingException('This route has scheduled trips. Cancel them first.', 409);
        }
        $route->delete();

        return response()->json(['message' => 'Route deleted.']);
    }
}
