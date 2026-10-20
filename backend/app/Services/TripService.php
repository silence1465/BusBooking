<?php

namespace App\Services;

use App\Models\Seat;
use App\Models\SeatHold;
use App\Models\Trip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TripService
{
    /**
     * Bookable trips: scheduled ones in the future, plus fill-and-go vehicles that are open at the station
     * (only the next one with free seats is offered per route, since they leave when full).
     */
    public function search(?string $from, ?string $to, ?string $date): Collection
    {
        Trip::carryQueueOver();
        $days = (int) config('busbooking.browse_days', 14);

        $query = fn (string $status) => Trip::query()
            ->with(['bus', 'fares', 'route.pickupPoints' => fn ($q) => $q->where('status', 'active')->orderBy('stop_order')->orderBy('name')])
            ->where('status', $status)
            // No date = browse everything open: the next couple of weeks of timetabled trips and today's queue.
            ->when($date, fn (Builder $q) => $q->whereDate('travel_date', $date), fn (Builder $q) => $q->whereBetween('travel_date', [today()->toDateString(), today()->addDays($days)->toDateString()]))
            ->whereHas('route', fn (Builder $q) => $q->where('status', 'active'))
            // "From" matches where the route starts, or (on a many-starts trip) any starting place the bus serves.
            ->when($from, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('route', fn (Builder $r) => $r->where('origin', 'like', $this->like($from)))
                ->orWhere(fn (Builder $o) => $o->where('fares_by', Trip::FARES_BY_ORIGIN)
                    ->whereHas('fares', fn (Builder $f) => $f->where('destination', 'like', $this->like($from))))))
            // "To" matches where the route ends, or (on a one-start trip) any destination the bus serves.
            ->when($to, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereHas('route', fn (Builder $r) => $r->where('destination', 'like', $this->like($to)))
                ->orWhere(fn (Builder $o) => $o->where('fares_by', Trip::FARES_BY_DESTINATION)
                    ->whereHas('fares', fn (Builder $f) => $f->where('destination', 'like', $this->like($to))))))
            ->whereHas('bus', fn (Builder $q) => $q->where('status', 'active'));

        $scheduled = $query(Trip::SCHEDULED)->orderBy('travel_date')->orderBy('departure_time')->get()
            ->filter(fn (Trip $t) => $t->lastDepartsAt()?->isFuture());

        // Per fill-and-go route: the car customers can book now, then the car behind it ("next car", not bookable yet).
        $boarding = $this->withAvailability($query(Trip::BOARDING)->get()->concat($query(Trip::QUEUED)->get())->sortBy('queue_position')->values())
            ->groupBy('route_id')
            ->flatMap(function (Collection $vehicles) {
                $vehicles = $vehicles->values();
                $now = $vehicles->first(fn (Trip $t) => $t->getAttribute('available_seats') > 0) ?? $vehicles->first();
                $now->setAttribute('queue_role', 'now');
                $result = collect([$now]);

                $next = $vehicles->first(fn (Trip $t) => $t->queue_position > $now->queue_position);
                if ($next) {
                    $next->setAttribute('queue_role', 'next');
                    $result->push($next);
                }

                return $result;
            })
            ->values();

        return $this->withAvailability($scheduled->values()->concat($boarding));
    }

    public function withAvailability(Collection $trips): Collection
    {
        $taken = SeatHold::query()
            ->whereIn('trip_id', $trips->pluck('id'))
            ->where(fn ($q) => $q->where('status', SeatHold::BOOKED)->orWhere('expires_at', '>', now()))
            ->selectRaw('trip_id, count(*) as c')->groupBy('trip_id')->pluck('c', 'trip_id');

        return $trips->each(function (Trip $trip) use ($taken) {
            $seatsTaken = (int) ($taken[$trip->id] ?? 0);
            $trip->setAttribute('seats_taken', $seatsTaken);
            $trip->setAttribute('available_seats', max(0, $trip->bus->seat_count - $seatsTaken));
        });
    }

    /** @return array<int, array{seat_number:int,status:string}> */
    public function seatMap(Trip $trip): array
    {
        $holds = SeatHold::where('trip_id', $trip->id)
            ->where(fn ($q) => $q->where('status', SeatHold::BOOKED)->orWhere('expires_at', '>', now()))
            ->pluck('status', 'seat_id');

        return Seat::where('bus_id', $trip->bus_id)->orderBy('seat_number')->get()
            ->map(fn (Seat $s) => [
                'seat_number' => $s->seat_number,
                'status' => $holds[$s->id] ?? 'available', // available | held | booked
            ])->all();
    }

    private function like(string $value): string
    {
        return '%'.addcslashes(trim($value), '%_\\').'%';
    }
}
