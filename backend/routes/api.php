<?php

use App\Http\Controllers\Api\Admin;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Public as PublicApi;
use App\Http\Controllers\Api\Station\ManageController;
use App\Http\Controllers\Api\Station\StationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bus Booking API (v1) - token based (Sanctum bearer tokens), usable by web and mobile.
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // ---- Auth -------------------------------------------------------------
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('my/bookings', [PublicApi\BookingController::class, 'mine']);
    });

    // ---- Public (guest) booking flow --------------------------------------
    Route::middleware('throttle:search')->group(function () {
        Route::get('locations', [PublicApi\TripController::class, 'locations']);
        Route::get('ads', [PublicApi\TripController::class, 'ads']);
        Route::get('site', [PublicApi\TripController::class, 'site']);
        Route::get('trips/search', [PublicApi\TripController::class, 'search']);
        Route::get('trips/{trip}', [PublicApi\TripController::class, 'show']);
    });
    Route::post('bookings', [PublicApi\BookingController::class, 'store'])->middleware('throttle:booking');
    Route::get('tickets/{code}', [PublicApi\BookingController::class, 'ticket'])->middleware('throttle:lookup');
    Route::post('payments/verify', [PublicApi\PaymentController::class, 'verify'])->middleware('throttle:lookup');
    Route::post('payments/webhook', [PublicApi\PaymentController::class, 'webhook']); // signature-verified

    // ---- Station master + driver (admin may also use these) -------------
    // Drivers ride the vehicle: they check passengers in and mark no-shows at pickup points.
    // Every action is still limited to the routes assigned to the user.
    Route::middleware(['auth:sanctum', 'role:station_master,driver,admin', 'throttle:station'])->prefix('station')->group(function () {
        Route::post('verify', [StationController::class, 'verify']);
        Route::post('bookings/{code}/confirm-arrival', [StationController::class, 'confirmArrival']);
        Route::post('bookings/{code}/no-show', [StationController::class, 'noShow']);
        Route::get('search', [StationController::class, 'search']);
        Route::get('trips', [StationController::class, 'trips']);
        Route::get('my-vehicle', [StationController::class, 'myVehicle']);
        Route::get('trips/{trip}/manifest', [StationController::class, 'manifest']);
    });

    // ---- Selling and dispatching: station master / admin only --------------
    Route::middleware(['auth:sanctum', 'role:station_master,admin', 'throttle:station'])->prefix('station')->group(function () {
        Route::get('trips/{trip}/seats', [StationController::class, 'seats']);
        Route::post('trips/{trip}/sell', [StationController::class, 'sell']);
        Route::get('boarding/setup', [StationController::class, 'boardingSetup']);
        Route::post('routes/{route}/open-boarding', [StationController::class, 'openBoarding']);
        Route::post('trips/{trip}/dispatch', [StationController::class, 'dispatch']);
        Route::post('trips/{trip}/queue', [StationController::class, 'queue']);
        Route::post('trips/{trip}/due', [StationController::class, 'due']);
        Route::get('departures', [StationController::class, 'departures']);
        Route::get('online-account', [StationController::class, 'onlineAccount']);
    });

    // ---- Station masters an admin has let create buses / pickup points / trips (each switched on separately) ----
    Route::middleware(['auth:sanctum', 'role:station_master,admin', 'throttle:station'])->prefix('manage')->group(function () {
        Route::get('routes', [ManageController::class, 'routes']);
        Route::get('vehicle-types', [ManageController::class, 'vehicleTypes']);
        Route::get('bus-options', [ManageController::class, 'buses']);
        Route::get('stops', [ManageController::class, 'stops']);

        Route::middleware('manage:buses')->group(function () {
            Route::get('buses', [Admin\BusController::class, 'index']);
            Route::post('buses', [Admin\BusController::class, 'store']);
            Route::put('buses/{bus}', [Admin\BusController::class, 'update']);
            Route::post('buses/{bus}/image', [Admin\BusController::class, 'uploadImage']);
        });
        Route::middleware('manage:pickup_points')->group(function () {
            Route::get('pickup-points', [Admin\PickupPointController::class, 'index']);
            Route::post('pickup-points', [Admin\PickupPointController::class, 'store']);
            Route::post('pickup-points/bulk', [Admin\PickupPointController::class, 'storeMany']);
            Route::put('pickup-points/{pickupPoint}', [Admin\PickupPointController::class, 'update']);
        });
        Route::middleware('manage:trips')->group(function () {
            Route::get('trips/summary', [Admin\TripController::class, 'summary']);
            Route::get('trips', [Admin\TripController::class, 'index']);
            Route::post('trips', [Admin\TripController::class, 'store']);
            Route::put('trips/{trip}', [Admin\TripController::class, 'update']);
        });
    });

    // ---- Admin only -------------------------------------------------------
    Route::middleware(['auth:sanctum', 'role:admin', 'throttle:api'])->prefix('admin')->group(function () {
        Route::get('dashboard', [Admin\DashboardController::class, 'stats']);
        Route::get('sms-logs', [Admin\DashboardController::class, 'smsLogs']);
        Route::get('sms-templates', [Admin\SmsController::class, 'templates']);
        Route::post('sms-templates', [Admin\SmsController::class, 'storeTemplate']);
        Route::put('sms-templates/{template}', [Admin\SmsController::class, 'updateTemplate']);
        Route::delete('sms-templates/{template}', [Admin\SmsController::class, 'destroyTemplate']);
        Route::get('sms/audience', [Admin\SmsController::class, 'audience']);
        Route::get('sms/recipients', [Admin\SmsController::class, 'recipients']);
        Route::post('sms/send', [Admin\SmsController::class, 'send']);
        Route::get('settings', [Admin\DashboardController::class, 'settings']);
        Route::put('settings', [Admin\DashboardController::class, 'updateSettings']);

        Route::apiResource('vehicle-categories', Admin\VehicleCategoryController::class)->except('show');
        Route::apiResource('buses', Admin\BusController::class)->except('show');
        Route::post('buses/{bus}/image', [Admin\BusController::class, 'uploadImage']);
        Route::apiResource('routes', Admin\RouteController::class)->except('show');
        Route::post('pickup-points/bulk', [Admin\PickupPointController::class, 'storeMany']);
        Route::apiResource('pickup-points', Admin\PickupPointController::class)->except('show');
        Route::get('trips/summary', [Admin\TripController::class, 'summary']);
        Route::apiResource('trips', Admin\TripController::class)->except('show');
        Route::apiResource('station-masters', Admin\StationMasterController::class)->except('show');

        Route::get('bookings', [Admin\BookingController::class, 'index']);
        Route::get('bookings/{booking}', [Admin\BookingController::class, 'show']);
        Route::post('bookings/{booking}/cancel', [Admin\BookingController::class, 'cancel']);

        Route::get('payments', [Admin\PaymentController::class, 'index']);
        Route::post('payments/{payment}/refunded', [Admin\PaymentController::class, 'markRefunded']);
    });
});
