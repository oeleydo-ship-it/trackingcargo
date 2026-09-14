<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\BulkTransitionBatchRequest;
use App\Models\ShipmentBatch;
use App\Services\Shipments\ShipmentBatchService;
use App\Services\Shipments\ShipmentStatusRepository;
use Illuminate\Http\RedirectResponse;

final class BatchTransitionController extends Controller
{
    public function store(BulkTransitionBatchRequest $request, ShipmentBatch $batch, ShipmentBatchService $batches, ShipmentStatusRepository $statuses): RedirectResponse
    {
        $result = $batches->bulkTransition(
            $batch,
            $statuses->byCode($request->validated('status'), (int) $batch->company_id),
            $request->user(),
            $request->validated('location'),
            $request->validated('description'),
            $request->boolean('is_public', true),
        );

        $applied = count($result['applied']);
        $skipped = count($result['skipped']);

        $message = $applied === 1 ? '1 shipment updated.' : "{$applied} shipments updated.";

        // Skips are normal — a batch can hold shipments at different stages —
        // so they are reported rather than treated as a failure.
        if ($skipped > 0) {
            $message .= ' '.($skipped === 1 ? '1 was skipped: ' : "{$skipped} were skipped: ");
            $message .= collect($result['skipped'])->map(fn (string $reason, string $number): string => "{$number} ({$reason})")->implode(', ').'.';
        }

        return back()->with($applied > 0 ? 'success' : 'error', $message);
    }
}
