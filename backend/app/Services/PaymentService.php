<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\Trip;
use App\Services\Payments\PaymentGateway;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly SeatHoldService $holds,
        private readonly SmsService $sms,
        private readonly BoardingService $boarding,
    ) {}

    /** @return string the Paystack checkout URL */
    public function initialize(Booking $booking, Payment $payment): string
    {
        $guest = Phone::digits($booking->phone).'@'.config('busbooking.guest_email_domain');
        $callback = config('busbooking.frontend_url').'/payment/callback';

        $start = fn (string $email) => $this->gateway->initialize(
            $email,
            (int) round($payment->amount * 100),
            $payment->currency,
            $payment->reference,
            $callback,
            ['booking_code' => $booking->code],
        );

        // The email is only for Paystack's receipt, never for sign-in. Paystack refuses some addresses (made-up
        // domains such as .test), and that must not stop someone from paying, so fall back to a guest address.
        $email = $this->usableEmail($booking->email) ?? $guest;
        try {
            $result = $start($email);
        } catch (\RuntimeException $e) {
            if ($email === $guest || ! str_contains(strtolower($e->getMessage()), 'email')) {
                throw $e;
            }
            $result = $start($guest);
        }

        return $result['authorization_url'];
    }

    /** The customer's email if it looks like a real, deliverable address; otherwise null. */
    private function usableEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $tld = strtolower(substr(strrchr($email, '.'), 1));

        // reserved names that can never receive mail
        return in_array($tld, ['test', 'example', 'invalid', 'localhost', 'local'], true) ? null : $email;
    }

    /**
     * Idempotent. Called from the browser callback AND the webhook; both ask Paystack
     * directly, so neither the frontend nor the webhook body is trusted for the outcome.
     */
    public function finalize(string $reference): ?Payment
    {
        $payment = Payment::where('reference', $reference)->first();
        if (! $payment) {
            return null;
        }
        if ($payment->status === Payment::PAID) {
            return $payment->load('booking');
        }

        $result = $this->gateway->verify($reference);
        $newlyConfirmed = null;

        DB::transaction(function () use ($payment, $result, &$newlyConfirmed) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->first();
            if ($payment->status === Payment::PAID) {
                return;
            }
            $booking = Booking::whereKey($payment->booking_id)->lockForUpdate()->first();

            if ($result->succeeded()) {
                $expectedMinor = (int) round($payment->amount * 100);
                if ($result->amountMinor !== $expectedMinor || strcasecmp((string) $result->currency, $payment->currency) !== 0) {
                    Log::critical('Paystack amount/currency mismatch', ['reference' => $payment->reference, 'got' => $result->amountMinor, 'expected' => $expectedMinor]);
                    $payment->forceFill(['status' => Payment::FAILED, 'notes' => 'Amount or currency mismatch with gateway. Needs manual review.'])->save();

                    return;
                }

                $payment->status = Payment::PAID;
                $payment->paid_at = now();
                $payment->channel = $result->channel;
                $payment->paystack_reference = $result->gatewayReference;
                $payment->notes = null;

                if ($booking->status === Booking::PENDING) {
                    $this->confirm($booking);
                    $newlyConfirmed = $booking;
                } elseif ($booking->status === Booking::CANCELLED && $booking->cancellation_reason === 'expired' && $this->reclaimSeats($booking)) {
                    $newlyConfirmed = $booking;
                } elseif ($booking->status !== Booking::CONFIRMED && $booking->status !== Booking::COMPLETED) {
                    $payment->notes = 'Paid but booking could not be confirmed (seats lost or booking cancelled). Refund required.';
                    Log::warning('Paid payment on unconfirmable booking', ['reference' => $payment->reference]);
                }
                $payment->save();
            } elseif ($result->status === 'failed') {
                $payment->forceFill(['status' => Payment::FAILED])->save();
                if ($booking->status === Booking::PENDING) {
                    $booking->forceFill(['status' => Booking::CANCELLED, 'cancelled_at' => now(), 'cancellation_reason' => 'payment_failed'])->save();
                    $this->holds->release($booking);
                }
            }
            // "pending" (abandoned / not yet paid): leave everything as is; the hold expires on its own.
        });

        // After commit, and isolated: an SMS problem never affects the booking.
        if ($newlyConfirmed) {
            $this->sms->sendBookingConfirmation($newlyConfirmed);
            $this->boarding->sync($newlyConfirmed->trip);
        }

        return $payment->fresh('booking');
    }

    private function confirm(Booking $booking): void
    {
        $booking->status = Booking::CONFIRMED;
        $booking->expires_at = null;
        $booking->save();
        $this->holds->markBooked($booking);
    }

    /** Customer paid just after the hold lapsed: take the seats back if nobody else did. */
    private function reclaimSeats(Booking $booking): bool
    {
        $trip = Trip::whereKey($booking->trip_id)->lockForUpdate()->first();
        if (! $trip || ! $trip->loadMissing('route')->isOpenForSale()) {
            return false;
        }

        $this->holds->expire($trip->id);
        $seats = $booking->seats()->with('seat')->get()->pluck('seat');
        if ($this->holds->unavailableSeatIds($trip->id, $seats->pluck('id'))->isNotEmpty()) {
            return false;
        }

        $this->holds->hold($booking, $seats, 'booked', null);
        $booking->status = Booking::CONFIRMED;
        $booking->cancelled_at = null;
        $booking->cancellation_reason = null;
        $booking->expires_at = null;
        $booking->save();

        return true;
    }
}
