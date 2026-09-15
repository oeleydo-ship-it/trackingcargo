<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ShipmentStatusColors;
use App\Http\Requests\Settings\StoreShipmentStatusRequest;
use App\Http\Requests\Settings\UpdateShipmentStatusRequest;
use App\Models\Branch;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\ShipmentStatusService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class ShipmentStatusController extends Controller
{
    /**
     * The company default workflow, or — with ?branch= — the workflow a
     * particular branch uses: its own copy when customised, otherwise the
     * default shown read-only with the option to customise it.
     */
    public function index(Request $request, ShipmentStatusRepository $repository): Response
    {
        $this->authorize('viewAny', ShipmentStatus::class);

        $companyId = app(TenantContext::class)->requireCompanyId();
        $branches = Branch::query()->orderBy('name')->get(['id', 'name']);
        $customised = $repository->customisedBranchIds($companyId);

        $branchId = $request->integer('branch') ?: null;
        $branch = $branchId !== null ? $branches->firstWhere('id', $branchId) : null;
        $branchId = $branch?->getKey();

        $scope = $branchId !== null && in_array((int) $branchId, $customised, true) ? (int) $branchId : 0;
        $statuses = ShipmentStatus::query()->where('scope', $scope)->ordered()->get();

        // How many shipments sit in each status, counting only shipments that
        // actually use the workflow on screen, so the page can warn before
        // someone retires a status that is in use.
        $counts = Shipment::query()
            ->when(
                $branchId !== null,
                fn ($query) => $query->where('branch_id', $branchId),
                fn ($query) => $query->where(fn ($query) => $query->whereNull('branch_id')
                    ->when($customised !== [], fn ($query) => $query->orWhereNotIn('branch_id', $customised), fn ($query) => $query->orWhereNotNull('branch_id'))),
            )
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $edges = DB::table('shipment_status_transitions')
            ->whereIn('from_status_id', $statuses->modelKeys())
            ->get(['from_status_id', 'to_status_id'])
            ->groupBy('from_status_id')
            ->map(fn ($group) => $group->pluck('to_status_id')->map(fn ($id): int => (int) $id)->values()->all());

        return Inertia::render('Settings/ShipmentStatuses/Index', [
            'statuses' => $statuses->map(fn (ShipmentStatus $status): array => [
                'id' => $status->getKey(),
                'code' => $status->code,
                'name' => $status->name,
                'color' => $status->color,
                'role' => $status->role?->value,
                'role_label' => $status->role?->label(),
                'system_use' => $status->role?->systemUse(),
                'sequence' => $status->sequence,
                'is_public' => $status->is_public,
                'is_terminal' => $status->is_terminal,
                'is_initial' => $status->is_initial,
                'is_active' => $status->is_active,
                'shipment_count' => (int) ($counts[$status->code] ?? 0),
                'transitions_to' => $edges->get($status->getKey(), []),
            ])->all(),
            'branches' => $branches->map(fn (Branch $branch): array => [
                'id' => $branch->getKey(),
                'name' => $branch->name,
                'customised' => in_array((int) $branch->getKey(), $customised, true),
            ])->all(),
            'selectedBranchId' => $branchId,
            // A branch picked that still uses the default: the page shows the
            // default read-only, with "Customise for this branch".
            'inheritsDefault' => $branchId !== null && $scope === 0,
            'colors' => ShipmentStatusColors::ALL,
            'canManage' => $request->user()?->can('create', ShipmentStatus::class) ?? false,
        ]);
    }

    public function store(StoreShipmentStatusRequest $request, ShipmentStatusService $statuses): RedirectResponse
    {
        $statuses->create($request->validated(), $request->user());

        return back()->with('success', 'Status added.');
    }

    public function update(UpdateShipmentStatusRequest $request, ShipmentStatus $shipmentStatus, ShipmentStatusService $statuses): RedirectResponse
    {
        $statuses->update($shipmentStatus, $request->validated(), $request->user());

        return back()->with('success', 'Status updated.');
    }

    public function destroy(ShipmentStatus $shipmentStatus, ShipmentStatusService $statuses): RedirectResponse
    {
        $this->authorize('delete', $shipmentStatus);

        $statuses->delete($shipmentStatus, request()->user());

        return back()->with('success', 'Status removed.');
    }

    public function customiseBranch(Request $request, Branch $branch, ShipmentStatusService $statuses): RedirectResponse
    {
        $this->authorize('create', ShipmentStatus::class);

        try {
            $statuses->customiseBranch($branch, $request->user());
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return redirect()->route('settings.shipmentStatuses.index', ['branch' => $branch->getKey()])
            ->with('success', "{$branch->name} now has its own shipment workflow, copied from the company default. Changes here affect only {$branch->name}.");
    }

    public function resetBranch(Request $request, Branch $branch, ShipmentStatusService $statuses): RedirectResponse
    {
        $this->authorize('create', ShipmentStatus::class);

        try {
            $statuses->resetBranch($branch, $request->user());
        } catch (ValidationException $exception) {
            return back()->with('error', $exception->validator->errors()->first());
        }

        return redirect()->route('settings.shipmentStatuses.index', ['branch' => $branch->getKey()])
            ->with('success', "{$branch->name} uses the company default workflow again.");
    }
}
