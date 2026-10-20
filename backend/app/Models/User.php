<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_STATION_MASTER = 'station_master';
    public const ROLE_DRIVER = 'driver';
    public const ROLE_CUSTOMER = 'customer';

    // role and is_active are deliberately NOT mass assignable.
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_manage_buses' => 'boolean',
            'can_manage_pickup_points' => 'boolean',
            'can_manage_trips' => 'boolean',
        ];
    }

    public const MANAGE = ['buses', 'pickup_points', 'trips'];

    /**
     * May this person create and edit buses / pickup points / trips? Admins always; a station master only when
     * an admin switched it on for them. Everyone else never.
     */
    public function canManage(string $what): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $this->role === self::ROLE_STATION_MASTER && in_array($what, self::MANAGE, true) && (bool) $this->getAttribute('can_manage_'.$what);
    }

    /** @return array<string, bool> */
    public function permissions(): array
    {
        return collect(self::MANAGE)->mapWithKeys(fn ($w) => [$w => $this->canManage($w)])->all();
    }

    /** Routes a station master is allowed to work on. */
    public function assignedRoutes(): BelongsToMany
    {
        return $this->belongsToMany(BusRoute::class, 'route_user', 'user_id', 'route_id')->withTimestamps();
    }

    /** Admins can work on every route; station masters only on the routes assigned to them. */
    public function canAccessRoute(int $routeId): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($this->role, [self::ROLE_STATION_MASTER, self::ROLE_DRIVER], true)
            && $this->assignedRoutes()->where('routes.id', $routeId)->exists();
    }

    public function bus(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Bus::class)->withTrashed();
    }

    /**
     * Route access, plus: a driver linked to a bus can only work on that bus's trips.
     * (A driver with no bus linked falls back to route access.)
     */
    public function canWorkOnTrip(Trip $trip): bool
    {
        if (! $this->canAccessRoute($trip->route_id)) {
            return false;
        }

        return $this->role !== self::ROLE_DRIVER || $this->bus_id === null || $this->bus_id === $trip->bus_id;
    }

    /** @return int[]|null null means "no restriction" (admin) */
    public function accessibleRouteIds(): ?array
    {
        return $this->isAdmin() ? null : $this->assignedRoutes()->pluck('routes.id')->all();
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }
}
