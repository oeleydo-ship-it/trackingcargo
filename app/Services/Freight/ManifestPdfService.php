<?php

declare(strict_types=1);

namespace App\Services\Freight;

use App\Models\Manifest;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;

final readonly class ManifestPdfService
{
    public function render(Manifest $manifest): string
    {
        $html = View::make('manifests.pdf', ['manifest' => $manifest])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
