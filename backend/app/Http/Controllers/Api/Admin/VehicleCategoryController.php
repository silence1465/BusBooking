<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\VehicleCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vehicle groups that pay the same station due when they depart (e.g. Minibus GHS 10, Coaster GHS 15). */
class VehicleCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => VehicleCategory::withCount('buses')->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        return response()->json(['data' => VehicleCategory::create($data)->fresh()], 201); // fresh(): include column defaults
    }

    public function update(Request $request, VehicleCategory $vehicleCategory): JsonResponse
    {
        $data = $request->validate($this->rules($vehicleCategory->id));
        $vehicleCategory->update($data);

        return response()->json(['data' => $vehicleCategory]);
    }

    public function destroy(VehicleCategory $vehicleCategory): JsonResponse
    {
        if ($vehicleCategory->buses()->exists()) {
            throw new BookingException('Some buses are in this category. Move them to another category first.', 409);
        }
        $vehicleCategory->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    private function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80', Rule::unique('vehicle_categories', 'name')->ignore($ignoreId)->whereNull('deleted_at')],
            'station_due' => ['required', 'numeric', 'min:0', 'max:100000'],
            'seat_selection' => ['sometimes', 'boolean'],
        ];
    }
}
