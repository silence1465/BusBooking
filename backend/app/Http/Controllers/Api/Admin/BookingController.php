<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    private const WITH = ['trip.route', 'trip.bus', 'pickupPoint', 'seats', 'payment', 'confirmedBy'];

    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request)
    {
        $q = $request->query('q');

        $paginator = Booking::with(self::WITH)
            ->when($request->query('status'), fn ($query, $s) => $query->where('status', $s))
            ->when($request->query('trip_id'), fn ($query, $t) => $query->where('trip_id', $t))
            ->when($request->query('date'), fn ($query, $d) => $query->whereHas('trip', fn ($t) => $t->whereDate('travel_date', $d)))
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('code', 'like', '%'.strtoupper(trim($q)).'%')
                    ->orWhere('passenger_name', 'like', '%'.trim($q).'%');
                if (strlen(Phone::digits($q)) >= 5) {
                    $w->orWhere('phone', 'like', '%'.Phone::suffix($q).'%');
                }
            }))
            ->latest()->paginate(20);

        return BookingResource::collection($paginator);
    }

    public function show(Booking $booking): BookingResource
    {
        return new BookingResource($booking->load(self::WITH));
    }

    public function cancel(Request $request, Booking $booking): JsonResponse
    {
        $this->bookings->cancel($booking, 'cancelled_by_admin');

        return response()->json(['data' => new BookingResource($booking->fresh(self::WITH))]);
    }
}
