<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Models\Bus;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

class BusService
{
    public function create(array $data): Bus
    {
        return DB::transaction(function () use ($data) {
            $bus = Bus::create($data);
            $this->syncSeats($bus);

            return $bus;
        });
    }

    public function update(Bus $bus, array $data): Bus
    {
        return DB::transaction(function () use ($bus, $data) {
            if (isset($data['seat_count']) && (int) $data['seat_count'] !== $bus->seat_count) {
                if ($bus->trips()->exists()) {
                    throw new BookingException('Seat count cannot be changed once the bus has trips.');
                }
            }
            $bus->update($data);
            $this->syncSeats($bus);

            return $bus;
        });
    }

    public function delete(Bus $bus): void
    {
        if ($bus->trips()->whereIn('status', [Trip::SCHEDULED, ...Trip::QUEUE_STATUSES])->exists()) {
            throw new BookingException('This bus has scheduled trips. Cancel or reassign them first.', 409);
        }
        $bus->delete();
    }

    /** Seats are numbered 1..seat_count. */
    private function syncSeats(Bus $bus): void
    {
        $existing = $bus->seats()->pluck('seat_number')->all();
        $missing = array_diff(range(1, $bus->seat_count), $existing);
        $now = now();

        if ($missing) {
            $bus->seats()->insert(array_map(
                fn ($n) => ['bus_id' => $bus->id, 'seat_number' => $n, 'created_at' => $now, 'updated_at' => $now],
                $missing,
            ));
        }
        $bus->seats()->where('seat_number', '>', $bus->seat_count)->delete();
    }
}
