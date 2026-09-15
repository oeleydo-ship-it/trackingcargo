<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentStatusRole;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads shipment workflows: which statuses exist, what each one means to the
 * system, and which moves between them are legal.
 *
 * A company has a default workflow (rows with scope 0), and any branch may be
 * customised with a complete workflow of its own (rows with scope = that
 * branch's id). A shipment uses its branch's workflow when that branch is
 * customised, and the company default otherwise. Every lookup that concerns a
 * shipment therefore takes the shipment's branch; passing no branch means the
 * company default.
 *
 * Workflows are read many times per request — the transition screens ask per
 * shipment — so each set is loaded once and held for the rest of the request.
 * Settings writes call forget() so an edit is visible to the redirect that
 * follows it.
 */
final class ShipmentStatusRepository
{
    /** @var array<string, Collection<int, ShipmentStatus>> keyed "company:scope" */
    private array $sets = [];

    /** @var array<int, list<int>> company => customised branch ids */
    private array $customised = [];

    /** @var array<int, array<int, list<int>>> */
    private array $edges = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The workflow a branch actually uses — its own if customised, otherwise
     * the company default — including inactive statuses, since a shipment
     * already sitting in a retired status must still render with its name.
     *
     * @return Collection<int, ShipmentStatus>
     */
    public function all(?int $companyId = null, ?int $branchId = null): Collection
    {
        $companyId ??= $this->tenantContext->requireCompanyId();

        return $this->scopeSet($companyId, $this->scopeFor($companyId, $branchId));
    }

    /**
     * One exact set of rows: 0 for the company default, a branch id for that
     * branch's own copy (empty when the branch is not customised).
     *
     * @return Collection<int, ShipmentStatus>
     */
    public function scopeSet(int $companyId, int $scope): Collection
    {
        return $this->sets["{$companyId}:{$scope}"] ??= ShipmentStatus::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('scope', $scope)
            ->whereNull('deleted_at')
            ->ordered()
            ->get()
            ->keyBy(fn (ShipmentStatus $status): int => (int) $status->getKey());
    }

    /** Which set a branch reads from: its own id when customised, else 0. */
    public function scopeFor(int $companyId, ?int $branchId): int
    {
        return $branchId !== null && $this->isCustomised($companyId, $branchId) ? $branchId : 0;
    }

    public function isCustomised(int $companyId, int $branchId): bool
    {
        return in_array($branchId, $this->customisedBranchIds($companyId), true);
    }

    /** @return list<int> */
    public function customisedBranchIds(int $companyId): array
    {
        return $this->customised[$companyId] ??= DB::table('shipment_statuses')
            ->where('company_id', $companyId)
            ->where('scope', '>', 0)
            ->whereNull('deleted_at')
            ->distinct()
            ->pluck('scope')
            ->map(fn ($scope): int => (int) $scope)
            ->values()
            ->all();
    }

    /**
     * The statuses a clerk may choose from — active ones only.
     *
     * @return Collection<int, ShipmentStatus>
     */
    public function selectable(?int $companyId = null, ?int $branchId = null): Collection
    {
        return $this->all($companyId, $branchId)->filter(fn (ShipmentStatus $status): bool => $status->is_active)->values();
    }

    public function byCode(string $code, ?int $companyId = null, ?int $branchId = null): ?ShipmentStatus
    {
        return $this->all($companyId, $branchId)->first(fn (ShipmentStatus $status): bool => $status->code === $code);
    }

    /**
     * The status carrying a given system behaviour in a branch's workflow.
     *
     * Throws rather than returning null: every role in
     * ShipmentStatusRole::systemDriven() is protected from deletion in every
     * workflow, so a missing one means the workflow has been corrupted, and a
     * customs clearance silently not moving its shipment would be worse than a
     * loud failure.
     */
    public function byRole(ShipmentStatusRole $role, ?int $companyId = null, ?int $branchId = null): ShipmentStatus
    {
        $status = $this->all($companyId, $branchId)->first(fn (ShipmentStatus $s): bool => $s->role === $role);

        if ($status === null) {
            throw new RuntimeException("This workflow has no shipment status for the \"{$role->value}\" role.");
        }

        return $status;
    }

    /** The status a shipment booked in this branch is created in. */
    public function initial(?int $companyId = null, ?int $branchId = null): ShipmentStatus
    {
        $status = $this->all($companyId, $branchId)->first(fn (ShipmentStatus $s): bool => $s->is_initial);

        if ($status === null) {
            throw new RuntimeException('This workflow has no starting shipment status.');
        }

        return $status;
    }

    /**
     * @return Collection<int, ShipmentStatus>
     */
    public function forShipment(Shipment $shipment): Collection
    {
        return $this->all((int) $shipment->company_id, $shipment->branch_id !== null ? (int) $shipment->branch_id : null);
    }

    public function statusOf(Shipment $shipment): ?ShipmentStatus
    {
        return $this->forShipment($shipment)->first(fn (ShipmentStatus $status): bool => $status->code === $shipment->status);
    }

    /**
     * Whether a status belongs to the workflow this shipment uses — so a
     * status from another branch's copy can never be applied to it.
     */
    public function appliesTo(ShipmentStatus $status, Shipment $shipment): bool
    {
        return $this->forShipment($shipment)->has((int) $status->getKey());
    }

    /**
     * Sets each shipment's resolved status, for pages that list shipments and
     * show their status name and colour.
     *
     * @param  iterable<Shipment>  $shipments
     */
    public function attach(iterable $shipments): void
    {
        foreach ($shipments as $shipment) {
            $shipment->setRelation('shipmentStatus', $this->statusOf($shipment));
        }
    }

    /**
     * The statuses a shipment currently in $from may be moved to, within
     * $from's own workflow.
     *
     * Inactive targets are left out: retiring a status should stop new
     * shipments arriving in it without rewriting the transition rules.
     *
     * @return Collection<int, ShipmentStatus>
     */
    public function allowedFrom(ShipmentStatus $from): Collection
    {
        $companyId = (int) $from->company_id;
        $targetIds = $this->edgesFor($companyId)[(int) $from->getKey()] ?? [];
        $set = $this->scopeSet($companyId, (int) $from->scope);

        return collect($targetIds)
            ->map(fn (int $id): ?ShipmentStatus => $set->get($id))
            ->filter(fn (?ShipmentStatus $status): bool => $status !== null && $status->is_active)
            ->sortBy([['sequence', 'asc'], ['name', 'asc']])
            ->values();
    }

    public function isAllowed(ShipmentStatus $from, ShipmentStatus $to): bool
    {
        return (int) $from->scope === (int) $to->scope
            && in_array((int) $to->getKey(), $this->edgesFor((int) $from->company_id)[(int) $from->getKey()] ?? [], true);
    }

    /** Drops the cached workflows after a settings change. */
    public function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->sets = [];
            $this->customised = [];
            $this->edges = [];

            return;
        }

        foreach (array_keys($this->sets) as $key) {
            if (str_starts_with($key, "{$companyId}:")) {
                unset($this->sets[$key]);
            }
        }

        unset($this->customised[$companyId], $this->edges[$companyId]);
    }

    /**
     * @return array<int, list<int>>
     */
    private function edgesFor(int $companyId): array
    {
        if (isset($this->edges[$companyId])) {
            return $this->edges[$companyId];
        }

        $map = [];

        foreach (DB::table('shipment_status_transitions')->where('company_id', $companyId)->get(['from_status_id', 'to_status_id']) as $edge) {
            $map[(int) $edge->from_status_id][] = (int) $edge->to_status_id;
        }

        return $this->edges[$companyId] = $map;
    }
}
