<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    /**
     * Browser callback. The reference is only a pointer: the backend asks Paystack for the
     * real outcome, so a forged "success" from the frontend cannot confirm anything.
     */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:100']]);

        $payment = $this->payments->finalize($data['reference']);
        abort_unless($payment, 404, 'Payment not found.');

        $booking = $payment->booking->load(['trip.route', 'trip.bus', 'pickupPoint', 'seats', 'payment', 'confirmedBy']);

        return response()->json(['data' => [
            'payment_status' => $payment->status,
            'booking' => new BookingResource($booking),
        ]]);
    }

    /** Paystack server-to-server webhook. */
    public function webhook(Request $request): JsonResponse
    {
        $secret = (string) config('busbooking.payment.paystack.secret_key');
        $signature = (string) $request->header('x-paystack-signature');

        if ($secret === '' || ! hash_equals(hash_hmac('sha512', $request->getContent(), $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ($request->input('event') === 'charge.success' && ($reference = $request->input('data.reference'))) {
            $this->payments->finalize((string) $reference);
        }

        return response()->json(['message' => 'ok']);
    }
}
