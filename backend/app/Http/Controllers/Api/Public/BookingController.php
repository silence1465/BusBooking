<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Services\BookingService;
use App\Support\BookingCode;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BookingController extends Controller
{
    private const WITH = ['trip.route', 'trip.bus', 'pickupPoint', 'seats', 'payment', 'confirmedBy'];

    public function __construct(private readonly BookingService $bookings) {}

    public function store(StoreBookingRequest $request): JsonResponse
    {
        // Optional auth: logged-in customers get the booking linked to their account.
        $user = Auth::guard('sanctum')->user();

        $result = $this->bookings->create($request->validated(), $user);

        return response()->json(['data' => [
            'booking' => new BookingResource($result['booking']->load(self::WITH)),
            'payment_reference' => $result['payment']->reference,
            'authorization_url' => $result['authorization_url'],
        ]], 201);
    }

    /** Guest ticket lookup: the booking code alone is not enough, the phone must match too. */
    public function ticket(Request $request, string $code): BookingResource
    {
        $request->validate(['phone' => ['required', 'string', 'min:7', 'max:20']]);

        $booking = Booking::with(self::WITH)->where('code', BookingCode::normalize($code))->first();

        abort_unless($booking && $booking->phone && Phone::matches($booking->phone, $request->input('phone')), 404, 'Booking not found. Check the code and phone number.');

        return new BookingResource($booking);
    }

    /** Bookings of the logged-in customer. */
    public function mine(Request $request)
    {
        $bookings = Booking::with(self::WITH)->where('user_id', $request->user()->id)
            ->whereIn('status', [Booking::CONFIRMED, Booking::COMPLETED, Booking::CANCELLED])
            ->latest()->paginate(15);

        return BookingResource::collection($bookings);
    }
}
