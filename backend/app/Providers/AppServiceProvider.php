<?php

namespace App\Providers;

use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaystackGateway;
use App\Services\Sms\ArkeselProvider;
use App\Services\Sms\LogProvider;
use App\Services\Sms\SmsProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentGateway::class, function () {
            $driver = config('busbooking.payment.driver');

            if ($driver === 'fake') {
                if ($this->app->isProduction()) {
                    throw new RuntimeException('PAYMENT_DRIVER=fake is not allowed in production.');
                }

                return new FakeGateway;
            }

            return new PaystackGateway(
                (string) config('busbooking.payment.paystack.secret_key'),
                config('busbooking.payment.paystack.base_url'),
            );
        });

        $this->app->bind(SmsProvider::class, function () {
            if (config('busbooking.sms.driver') === 'log') {
                return new LogProvider;
            }
            $c = config('busbooking.sms.arkesel');

            return new ArkeselProvider($c['api_key'], $c['sender_id'], $c['base_url']);
        });
    }

    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(5)->by(strtolower((string) ($r->input('login') ?? $r->input('email'))).'|'.$r->ip()));
        RateLimiter::for('search', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('booking', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        // Code/phone lookups: stops brute-forcing booking codes.
        RateLimiter::for('lookup', fn (Request $r) => Limit::perMinute(20)->by($r->ip()));
        RateLimiter::for('station', fn (Request $r) => Limit::perMinute(90)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
    }
}
