<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\Shipments\PublicTrackingService;
use Inertia\Inertia;
use Inertia\Response;

final class PublicTrackingController extends Controller
{
    /**
     * Serves both /track (a blank search box — the one link a company
     * publishes once for every customer to use) and /track/{trackingNumber}
     * (that same page pre-filled and already showing one shipment's
     * result, for a link that should land straight on it).
     */
    public function show(PublicTrackingService $tracking, ?string $trackingNumber = null): Response
    {
        return Inertia::render('Public/Tracking', [
            'trackingNumber' => $trackingNumber ?? '',
            'shipment' => $trackingNumber !== null ? $tracking->find($trackingNumber) : null,
        ]);
    }

    /**
     * The embeddable counterpart of show() — same two shapes (a blank
     * search widget at /track/embed, a single shipment's result at
     * /track/{trackingNumber}/embed), rendered with none of the site chrome
     * around it since it's meant to sit inside a few hundred pixels on
     * someone else's site. See EmbedCard on Public/Tracking.tsx for the
     * snippets these are rendered by.
     */
    public function embed(PublicTrackingService $tracking, ?string $trackingNumber = null): Response
    {
        return Inertia::render('Public/TrackingEmbed', [
            'trackingNumber' => $trackingNumber ?? '',
            'shipment' => $trackingNumber !== null ? $tracking->find($trackingNumber) : null,
        ]);
    }
}
