<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['origin', 'destination', 'status', 'mode', 'fare', 'commission'])]
class BusRoute extends Model
{
    use SoftDeletes;

    public const SCHEDULED = 'scheduled';
    public const FILL_AND_GO = 'fill_and_go';

    protected $table = 'routes';

    protected function casts(): array
    {
        return ['fare' => 'decimal:2', 'commission' => 'decimal:2'];
    }

    public function isFillAndGo(): bool
    {
        return $this->mode === self::FILL_AND_GO;
    }

    public function pickupPoints(): HasMany
    {
        return $this->hasMany(PickupPoint::class, 'route_id');
    }

    public function categoryCommissions(): HasMany
    {
        return $this->hasMany(RouteCommission::class, 'route_id');
    }

    /**
     * Online commission per seat on this route for a vehicle: the amount set for its vehicle type if there is
     * one, otherwise the route's own amount.
     */
    public function commissionFor(?Bus $bus): float
    {
        $special = $bus?->category_id
            ? $this->categoryCommissions()->where('vehicle_category_id', $bus->category_id)->value('commission')
            : null;

        return (float) ($special ?? $this->commission ?? 0);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'route_id');
    }
}
