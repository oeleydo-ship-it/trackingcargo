<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\StoreShipmentBatchRequest;
use App\Http\Requests\Shipments\UpdateShipmentBatchRequest;
use App\Models\Branch;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Services\Shipments\ShipmentBatchService;
use App\Services\Shipments\ShipmentStatusRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class ShipmentBatchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShipmentBatch::class);

        $user = $request->user();

        return Inertia::render('Batches/Index', [
            'batches' => ShipmentBatch::query()
                ->with('branch:id,name')
                ->withCount('shipments')
                // Mirrors ShipmentBatchPolicy::view() so the list never shows a
                // batch the actor would be refused on open.
                ->when(
                    $user?->branch_id !== null && ! $user->hasPermission('shipments.manage'),
                    fn ($query) => $query->where('branch_id', $user->branch_id),
                )
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'branches' => $this->bookableBranches($request),
        ]);
    }

    public function show(ShipmentBatch $batch): Response
    {
        $this->authorize('view', $batch);

        $batch->load(['branch:id,name', 'creator:id,name']);
        $companyId = (int) $batch->company_id;
        $shipments = $batch->shipments()
            ->where('shipments.company_id', $companyId)
            ->with([
                'customer:id,name',
                // Status codes are unique within a company, not globally.
                'shipmentStatus' => fn ($query) => $query->where('company_id', $companyId)->select('id', 'code', 'name', 'color'),
            ])->orderBy('id')->get();

        return Inertia::render('Batches/Show', [
            'batch' => $batch,
            'shipments' => $shipments,
            'allowedTransitions' => $this->reachableTransitions($shipments, $companyId),
            // Candidates for the "add shipments" picker: same branch, not yet
            // in any batch.
            'assignable' => Shipment::query()
                ->where('company_id', $companyId)
                ->where('branch_id', $batch->branch_id)
                ->whereNull('batch_id')
                ->orderByDesc('id')
                ->limit(100)
                ->get(['id', 'tracking_number', 'status', 'destination_city', 'destination_country_code']),
        ]);
    }

    public function store(StoreShipmentBatchRequest $request, ShipmentBatchService $batches): RedirectResponse
    {
        $batch = $batches->create($request->validated(), $request->user());

        return to_route('batches.show', $batch)->with('success', "Batch {$batch->batch_number} created.");
    }

    public function update(UpdateShipmentBatchRequest $request, ShipmentBatch $batch, ShipmentBatchService $batches): RedirectResponse
    {
        $batches->update($batch, $request->validated(), $request->user());

        return back()->with('success', 'Batch updated.');
    }

    public function destroy(ShipmentBatch $batch, ShipmentBatchService $batches): RedirectResponse
    {
        $this->authorize('delete', $batch);

        // Ungroups the members rather than taking them with it.
        $batches->delete($batch, request()->user());

        return to_route('batches.index')->with('success', "Batch {$batch->batch_number} removed.");
    }

    /**
     * The transitions offered for a bulk update: every status reachable from
     * at least one shipment currently in the batch, each tagged with how many
     * members it would actually move. A batch routinely mixes stages (a few
     * shipments still at customs while most are already in transit), and
     * ShipmentBatchService::bulkTransition() already applies a chosen status
     * only to the shipments it's legal for and reports the rest as skipped —
     * so restricting the picker to the intersection would block "mark the
     * ones that arrived as delivered" for no reason the backend requires.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return list<array{value: string, label: string, applicable: int}>
     */
    private function reachableTransitions(Collection $shipments, int $companyId): array
    {
        if ($shipments->isEmpty()) {
            return [];
        }

        $statuses = app(ShipmentStatusRepository::class);

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($shipments->pluck('status')->unique() as $code) {
            $from = $statuses->byCode((string) $code, $companyId);

            if ($from === null) {
                continue;
            }

            $memberCount = $shipments->where('status', $code)->count();

            foreach ($statuses->allowedFrom($from) as $to) {
                $counts[$to->code] = ($counts[$to->code] ?? 0) + $memberCount;
            }
        }

        return array_values(array_map(
            static fn (string $code, int $applicable): array => [
                'value' => $code,
                'label' => $statuses->byCode($code, $companyId)?->name ?? $code,
                'applicable' => $applicable,
            ],
            array_keys($counts),
            $counts,
        ));
    }

    /** @see ShipmentController::bookableBranches() — same branch-scoping rule. */
    private function bookableBranches(Request $request): Collection
    {
        $user = $request->user();

        return Branch::query()
            ->where('status', 'active')
            ->when(
                $user?->branch_id !== null && ! $user->hasPermission('branches.manage'),
                fn ($query) => $query->whereKey($user->branch_id),
            )
            ->orderByDesc('is_head_office')
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }
}
