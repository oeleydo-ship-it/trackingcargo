<?php

declare(strict_types=1);

use App\Services\Tracking\MockCarrierProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | Carrier provider registry
    |--------------------------------------------------------------------------
    |
    | Maps a carrier code (stored on shipments.carrier_code) to the
    | CarrierProviderInterface implementation that polls it. Add a real
    | integration by adding a class here — CarrierProviderRegistry and
    | CarrierTrackingPollService never need to change.
    |
    */
    'providers' => [
        'mock' => MockCarrierProvider::class,
    ],
];
