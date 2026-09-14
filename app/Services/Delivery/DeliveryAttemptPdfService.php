<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\DocumentCategory;
use App\Models\DeliveryAttempt;
use App\Models\Document;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

final readonly class DeliveryAttemptPdfService
{
    public function render(DeliveryAttempt $attempt): string
    {
        $attempt->loadMissing(['assignment.shipment', 'assignment.driver.user', 'documents']);

        $signature = $attempt->documents->firstWhere('category', DocumentCategory::PodSignature);

        $html = View::make('delivery-attempts.pdf', [
            'attempt' => $attempt,
            'signatureDataUri' => $signature !== null ? $this->toDataUri($signature) : null,
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function toDataUri(Document $document): string
    {
        $contents = Storage::disk($document->disk)->get($document->path);

        return 'data:'.($document->mime_type ?? 'image/png').';base64,'.base64_encode($contents);
    }
}
