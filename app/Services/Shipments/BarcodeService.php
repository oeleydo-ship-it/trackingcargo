<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Picqer\Barcode\BarcodeGeneratorSVG;

final readonly class BarcodeService
{
    public function code128Svg(string $code): string
    {
        $generator = new BarcodeGeneratorSVG;

        return $generator->getBarcode($code, BarcodeGeneratorSVG::TYPE_CODE_128, widthFactor: 2, height: 50);
    }

    public function qrSvg(string $data): string
    {
        $result = (new Builder(
            writer: new SvgWriter,
            data: $data,
            size: 220,
            margin: 8,
        ))->build();

        return $result->getString();
    }
}
