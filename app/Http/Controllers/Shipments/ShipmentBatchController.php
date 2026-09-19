<?php

declare(strict_types=1);

namespace App\Http\Controllers\Shipments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipments\BatchIndexRequest;
use App\Http\Requests\Shipments\StoreShipmentBatchRequest;
use App\Http\Requests\Shipments\UpdateShipmentBatchRequest;
use App\Models\Branch;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\ShipmentStatus;
use App\Models\User;
use App\Services\Shipments\ShipmentBatchService;
use App\Services\Shipments\ShipmentStatusRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

final class ShipmentBatchController extends Controller
{
    public function index(BatchIndexRequest $request): Response
    {
        $this->authorize('viewAny', ShipmentBatch::class);

        $user = $request->user();
        $filters = $request->filters();

        return Inertia::render('Batches/Index', [
            'batches' => $this->visibleBatches($user)
                ->with('branch:id,name')
                ->withCount('shipments')
                ->filteredBy($filters)
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
            'filters' => $filters,
            // Lazy, so typing in the search box reloads only the list itself.
            // Only branches that actually have a batch the viewer can see.
            'filterOptions' => fn (): array => [
                'branches' => Branch::query()
                    ->whereIn('id', $this->visibleBatches($user)->select('branch_id')->distinct())
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
            'branches' => $this->bookableBranches($request),
        ]);
    }

    /**
     * Mirrors ShipmentBatchPolicy::view() so the list — and the options its
     * filters offer — never include a batch the actor would be refused on open.
     *
     * @return Builder<ShipmentBatch>
     */
    private function visibleBatches(?User $user): Builder
    {
        return ShipmentBatch::query()->when(
            $user?->branch_id !== null && ! $user->hasPermission('shipments.manage'),
            fn (Builder $query) => $query->where('branch_id', $user->branch_id),
        );
    }

    public function show(ShipmentBatch $batch): Response
    {
        $this->authorize('view', $batch);

        $batch->load(['branch:id,name', 'creator:id,name']);
        $companyId = (int) $batch->company_id;
        $shipments = $batch->shipments()
            ->where('shipments.company_id', $companyId)
            ->with(['customer:id,name'])
            ->orderBy('id')
            ->get();

        // Names and colours from the batch branch's own workflow.
        app(ShipmentStatusRepository::class)->attach($shipments);

        return Inertia::render('Batches/Show', [
            'batch' => $batch,
            'shipments' => $shipments,
            'branchStatuses' => $this->branchStatuses($batch, $shipments),
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
     * The statuses offered for a bulk update: every active status in the
     * batch's branch workflow — its own if the branch is customised, the
     * company default otherwise — each tagged with how many of the batch's
     * shipments can actually move there from where they are now.
     *
     * The whole workflow is listed, not only what is reachable, so the picker
     * shows every status the branch uses. A batch routinely mixes stages, and
     * ShipmentBatchService::bulkTransition() applies a chosen status only to
     * the shipments it is legal for and reports the rest as skipped; a status
     * with `applicable` 0 is one the workflow does not allow from any current
     * status, and the page says so rather than offering to apply it.
     *
     * @param  Collection<int, Shipment>  $shipments
     * @return list<array{value: string, label: string, applicable: int}>
     */
    private function branchStatuses(ShipmentBatch $batch, Collection $shipments): array
    {
        if ($shipments->isEmpty()) {
            return [];
        }

        $companyId = (int) $batch->company_id;

        $statuses = app(ShipmentStatusRepository::class);

        /** @var array<string, int> $counts */
        $counts = [];

        foreach ($shipments->unique('status') as $member) {
            $code = $member->status;
            $from = $statuses->statusOf($member);

            if ($from === null) {
                continue;
            }

            $memberCount = $shipments->where('status', $code)->count();

            foreach ($statuses->allowedFrom($from) as $to) {
                $counts[$to->code] = ($counts[$to->code] ?? 0) + $memberCount;
            }
        }

        return $statuses->selectable($companyId, (int) $batch->branch_id)
            ->map(fn (ShipmentStatus $status): array => [
                'value' => $status->code,
                'label' => $status->name,
                'applicable' => $counts[$status->code] ?? 0,
            ])
            ->values()
            ->all();
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
