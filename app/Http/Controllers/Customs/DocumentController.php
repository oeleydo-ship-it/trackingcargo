<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customs;

use App\Enums\DocumentCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreDocumentRequest;
use App\Models\CustomsClearance;
use App\Models\Document;
use App\Models\Shipment;
use App\Services\Documents\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentController extends Controller
{
    public function store(StoreDocumentRequest $request, Shipment $shipment, CustomsClearance $clearance, DocumentService $documents): RedirectResponse
    {
        abort_unless($clearance->shipment_id === $shipment->getKey(), 404);

        $documents->upload($clearance, DocumentCategory::from($request->validated('category')), $request->file('file'), $request->user());

        return back()->with('success', 'Document uploaded.');
    }

    public function download(Shipment $shipment, CustomsClearance $clearance, Document $document): StreamedResponse
    {
        $this->authorize('view', $clearance);
        $this->assertBelongsToClearance($shipment, $clearance, $document);

        return Storage::disk($document->disk)->download($document->path, $document->original_filename);
    }

    public function destroy(Shipment $shipment, CustomsClearance $clearance, Document $document, DocumentService $documents): RedirectResponse
    {
        $this->authorize('update', $clearance);
        $this->assertBelongsToClearance($shipment, $clearance, $document);

        $documents->delete($document, request()->user());

        return back()->with('success', 'Document removed.');
    }

    private function assertBelongsToClearance(Shipment $shipment, CustomsClearance $clearance, Document $document): void
    {
        abort_unless(
            $clearance->shipment_id === $shipment->getKey()
                && $document->documentable_type === CustomsClearance::class
                && $document->documentable_id === $clearance->getKey(),
            404,
        );
    }
}
