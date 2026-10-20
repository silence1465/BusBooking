<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Services\BusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class BusController extends Controller
{
    public function __construct(private readonly BusService $buses) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => Bus::with('category:id,name,station_due')->orderBy('name')->get()->map(fn (Bus $b) => $this->present($b, $request))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'registration_number' => ['required', 'string', 'max:30', 'unique:buses,registration_number'],
            'seat_count' => ['required', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'category_id' => ['nullable', 'integer', Rule::exists('vehicle_categories', 'id')->whereNull('deleted_at')],
            // A special station due for this one bus; leave empty to use its category's due.
            'station_due_override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        $data['registration_number'] = strtoupper(trim($data['registration_number']));
        $data = $this->withoutMoneyFor($request, $data);

        return response()->json(['data' => $this->present($this->buses->create($data), $request)], 201);
    }

    public function update(Request $request, Bus $bus): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'registration_number' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('buses', 'registration_number')->ignore($bus->id)],
            'seat_count' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'category_id' => ['nullable', 'integer', Rule::exists('vehicle_categories', 'id')->whereNull('deleted_at')],
            // A special station due for this one bus; leave empty to use its category's due.
            'station_due_override' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);
        if (isset($data['registration_number'])) {
            $data['registration_number'] = strtoupper(trim($data['registration_number']));
        }

        return response()->json(['data' => $this->present($this->buses->update($bus, $this->withoutMoneyFor($request, $data)), $request)]);
    }

    /** Station dues are an admin's setting: a station master neither sets nor sees them. */
    private function withoutMoneyFor(Request $request, array $data): array
    {
        if (! $request->user()->isAdmin()) {
            unset($data['station_due_override']);
        }

        return $data;
    }

    private function present(Bus $bus, Request $request): Bus
    {
        if (! $request->user()->isAdmin()) {
            $bus->makeHidden('station_due_override');
            $bus->category?->makeHidden('station_due');
        }

        return $bus;
    }

    public function uploadImage(Request $request, Bus $bus): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        if ($bus->image_path) {
            Storage::disk('public')->delete($bus->image_path);
        }
        $bus->image_path = $request->file('image')->store('buses', 'public');
        $bus->save();

        return response()->json(['data' => $bus]);
    }

    public function destroy(Bus $bus): JsonResponse
    {
        $this->buses->delete($bus);

        return response()->json(['message' => 'Bus deleted.']);
    }
}
