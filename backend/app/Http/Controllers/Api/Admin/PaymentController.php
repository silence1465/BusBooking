<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with('booking:id,code,passenger_name,phone,status')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('date'), fn ($q, $d) => $q->whereDate('created_at', $d))
            ->latest()->paginate(20);

        return response()->json($payments);
    }

    /** Records that the money was returned (the actual refund is done in the Paystack dashboard in V1). */
    public function markRefunded(Payment $payment): JsonResponse
    {
        if ($payment->status !== Payment::PAID) {
            throw new BookingException('Only paid payments can be marked as refunded.', 409);
        }
        $payment->forceFill(['status' => Payment::REFUNDED, 'notes' => trim(($payment->notes ?? '').' Marked refunded by admin.')])->save();

        return response()->json(['data' => $payment]);
    }
}
