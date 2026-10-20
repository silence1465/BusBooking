<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StationMasterController extends Controller
{
    /** Roles an admin can create here. Admins themselves are never managed through the UI. */
    private const STAFF = [User::ROLE_STATION_MASTER, User::ROLE_DRIVER];

    public function index(): JsonResponse
    {
        $users = User::whereIn('role', self::STAFF)->with(['assignedRoutes:routes.id,origin,destination', 'bus'])
            ->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => $this->payload($u))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizePhone($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\s\-]{9,16}$/', 'unique:users,phone'],
            'password' => ['required', Password::min(8)],
            'role' => ['sometimes', Rule::in(self::STAFF)],
            ...$this->routeRules(),
            ...$this->permissionRules(),
            'bus_id' => ['nullable', 'integer', Rule::exists('buses', 'id')->whereNull('deleted_at')],
        ]);
        $permissions = array_intersect_key($data, array_flip($this->permissionKeys()));
        $routeIds = $data['route_ids'] ?? [];
        $role = $data['role'] ?? User::ROLE_STATION_MASTER;
        $busId = $role === User::ROLE_DRIVER ? ($data['bus_id'] ?? null) : null; // only drivers are tied to a bus
        unset($data['route_ids'], $data['role'], $data['bus_id']);
        $data = array_diff_key($data, $permissions);

        $user = new User($data);
        $user->role = $role;
        $this->applyPermissions($user, $permissions);
        $user->bus_id = $busId;
        $user->is_active = true;
        $user->save();
        $user->assignedRoutes()->sync($routeIds);

        return response()->json(['data' => $this->payload($user->load(['assignedRoutes', 'bus']))], 201);
    }

    public function update(Request $request, User $stationMaster): JsonResponse
    {
        abort_unless(in_array($stationMaster->role, self::STAFF, true), 404);

        $this->normalizePhone($request);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($stationMaster->id)],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+\s\-]{9,16}$/', Rule::unique('users', 'phone')->ignore($stationMaster->id)],
            'password' => ['sometimes', 'nullable', Password::min(8)],
            'is_active' => ['sometimes', 'boolean'],
            ...$this->routeRules(),
            ...$this->permissionRules(),
            'bus_id' => ['nullable', 'integer', Rule::exists('buses', 'id')->whereNull('deleted_at')],
        ]);
        if (array_key_exists('password', $data) && ! $data['password']) {
            unset($data['password']);
        }

        $permissions = array_intersect_key($data, array_flip($this->permissionKeys()));
        $data = array_diff_key($data, $permissions);
        $routeIds = $data['route_ids'] ?? null;
        $active = $data['is_active'] ?? null;
        $busGiven = array_key_exists('bus_id', $data);
        $busId = $data['bus_id'] ?? null;
        unset($data['is_active'], $data['route_ids'], $data['bus_id']);

        $stationMaster->fill($data);
        $this->applyPermissions($stationMaster, $permissions);
        if ($active !== null) {
            $stationMaster->is_active = $active;
            if (! $active) {
                $stationMaster->tokens()->delete(); // kick out immediately
            }
        }
        if ($busGiven || $stationMaster->role !== User::ROLE_DRIVER) {
            $stationMaster->bus_id = $stationMaster->role === User::ROLE_DRIVER ? $busId : null;
        }
        $stationMaster->save();

        if ($routeIds !== null) {
            $stationMaster->assignedRoutes()->sync($routeIds);
        }

        return response()->json(['data' => $this->payload($stationMaster->load(['assignedRoutes', 'bus']))]);
    }

    public function destroy(User $stationMaster): JsonResponse
    {
        abort_unless(in_array($stationMaster->role, self::STAFF, true), 404);
        $stationMaster->tokens()->delete();
        $stationMaster->delete();

        return response()->json(['message' => 'Station master removed.']);
    }

    /** The things a station master may create: can_manage_buses, can_manage_pickup_points, can_manage_trips. */
    private function permissionKeys(): array
    {
        return array_map(fn ($w) => 'can_manage_'.$w, User::MANAGE);
    }

    private function permissionRules(): array
    {
        return array_fill_keys($this->permissionKeys(), ['sometimes', 'boolean']);
    }

    /** Not mass assignable on purpose. Only station masters carry them: drivers and admins never do. */
    private function applyPermissions(User $user, array $permissions): void
    {
        foreach ($permissions as $key => $on) {
            $user->setAttribute($key, $user->role === User::ROLE_STATION_MASTER ? (bool) $on : false);
        }
    }

    private function routeRules(): array
    {
        return [
            'route_ids' => ['sometimes', 'array'],
            'route_ids.*' => ['integer', 'distinct', Rule::exists('routes', 'id')->whereNull('deleted_at')],
        ];
    }

    private function normalizePhone(Request $request): void
    {
        if (is_string($request->input('phone'))) {
            $request->merge(['phone' => Phone::normalize($request->input('phone'))]);
        }
    }

    private function payload(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role' => $u->role,
            'bus_id' => $u->bus_id,
            'bus' => $u->bus ? $u->bus->name.' · '.$u->bus->registration_number : null,
            'is_active' => $u->is_active,
            'can_manage_buses' => (bool) $u->can_manage_buses,
            'can_manage_pickup_points' => (bool) $u->can_manage_pickup_points,
            'can_manage_trips' => (bool) $u->can_manage_trips,
            'route_ids' => $u->assignedRoutes->pluck('id')->values(),
            'routes' => $u->assignedRoutes->map(fn ($r) => $r->origin.' → '.$r->destination)->values(),
        ];
    }
}
