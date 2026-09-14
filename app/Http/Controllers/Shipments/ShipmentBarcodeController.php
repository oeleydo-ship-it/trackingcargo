<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Services\Shipments\BarcodeService;
use Illuminate\Http\Response;

final class ShipmentBarcodeController extends Controller
{
    public function shipment(Shipment $shipment, BarcodeService $barcodes): Response
    {
        $this->authorize('view', $shipment);

        return $this->svgResponse($barcodes->code128Svg($shipment->tracking_number));
    }

    public function shipmentQr(Shipment $shipment, BarcodeService $barcodes): Response
    {
        $this->authorize('view', $shipment);

        return $this->svgResponse($barcodes->qrSvg(route('public.tracking.show', $shipment->tracking_number)));
    }

    public function package(Shipment $shipment, ShipmentPackage $package, BarcodeService $barcodes): Response
    {
        $this->authorize('view', $shipment);
        abort_unless($package->shipment_id === $shipment->getKey(), 404);

        return $this->svgResponse($barcodes->code128Svg($package->barcode));
    }

    private function svgResponse(string $svg): Response
    {
        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }
}
