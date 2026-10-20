<?php

namespace App\Services;

use App\Jobs\DeliverSms;
use App\Models\Booking;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Services\Sms\SmsProvider;
use App\Support\Phone;
use Illuminate\Support\Facades\Log;
use Throwable;

class SmsService
{
    public function __construct(private readonly SmsProvider $provider) {}

    /**
     * Queue an SMS and return straight away: the page, the payment callback or the dispatch never waits for the
     * SMS provider. Delivery happens right after the response is sent (default), or on a queue worker when
     * SMS_DELIVERY=queue. Never throws: an SMS problem must not affect a paid booking.
     */
    public function send(string $phone, string $message, ?int $bookingId = null, ?string $type = null): SmsLog
    {
        // An admin can turn each kind of automatic text off. It is still recorded, as skipped, so nothing vanishes silently.
        if ($type !== null && ! Setting::smsEnabled($type)) {
            return SmsLog::create([
                'booking_id' => $bookingId,
                'phone' => $phone,
                'message' => $message,
                'provider' => $this->provider->name(),
                'status' => 'skipped',
                'provider_response' => 'Turned off in SMS settings',
            ]);
        }

        $log = SmsLog::create([
            'booking_id' => $bookingId,
            'phone' => $phone,
            'message' => $message,
            'provider' => $this->provider->name(),
            'status' => 'queued',
        ]);

        try {
            if (config('busbooking.sms.delivery') === 'queue') {
                DeliverSms::dispatch($log->id)->onQueue('sms');
            } else {
                DeliverSms::dispatchAfterResponse($log->id);
            }
        } catch (Throwable $e) {
            // Could not even queue it: record that, keep going.
            Log::error('SMS could not be queued', ['error' => $e->getMessage()]);
            $log->update(['status' => 'failed', 'provider_response' => 'Could not be queued: '.$e->getMessage()]);
        }

        return $log;
    }

    /** Talk to the provider and record the outcome. One immediate retry: a text that is late is nearly as bad as none. */
    public function deliver(SmsLog $log): SmsLog
    {
        $result = $this->attempt($log);
        if (! $result['ok']) {
            $result = $this->attempt($log);
        }

        $log->update([
            'status' => $result['ok'] ? 'sent' : 'failed',
            'provider_response' => $result['response'],
            'sent_at' => $result['ok'] ? now() : null,
        ]);

        return $log;
    }

    /** @return array{ok: bool, response: string} */
    private function attempt(SmsLog $log): array
    {
        try {
            return $this->provider->send(Phone::international($log->phone), $log->message);
        } catch (Throwable $e) {
            Log::error('SMS send failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'response' => $e->getMessage()];
        }
    }

    public function sendBookingConfirmation(Booking $booking): SmsLog
    {
        $booking->loadMissing(['trip.route', 'pickupPoint', 'seats']);
        $trip = $booking->trip;
        $seats = $booking->seats->pluck('seat_number')->implode(', ');

        $trip->loadMissing('bus.category');
        $seatText = $trip->bus->allowsSeatSelection()
            ? 'Seat '.$seats
            : $booking->seats->count().($booking->seats->count() === 1 ? ' seat, sit anywhere' : ' seats, sit anywhere');
        $when = $trip->departsAtFor($booking->pickup_point_id)?->format('g:i A') ?? 'leaves when full';
        $message = sprintf(
            'Booking confirmed. %s to %s, %s, %s. Pickup: %s.%s %s. Booking Code: %s.',
            $trip->route->origin,
            $trip->route->destination,
            $trip->travel_date->format('j M Y'),
            $when,
            $booking->pickupPoint?->name ?? 'station',
            $booking->destination ? ' Destination: '.$booking->destination.'.' : ($booking->journey_from ? ' From: '.$booking->journey_from.'.' : ''),
            $seatText,
            $booking->code,
        );

        if ($trip->isFillAndGo()) {
            $message .= $booking->pickupPoint?->is_main_station
                ? ' Be at the station when the bus fills up.'
                : ' We will text you when the bus leaves the station.';
        }

        return $this->send($booking->phone, $message, $booking->id, 'tickets');
    }
}
