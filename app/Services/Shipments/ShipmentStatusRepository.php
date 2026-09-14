<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentStatusRole;
use App\Models\ShipmentStatus;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads a company's status workflow: which statuses exist, what each one
 * means to the system, and which moves between them are legal.
 *
 * This replaces the hardcoded ShipmentTransitionMap. A status set is read
 * many times per request — the transition screen alone asks for it once per
 * shipment — so each company's set is loaded once and held for the rest of
 * the request. Settings writes call forget() so an edit is visible to the
 * redirect that follows it.
 */
final class ShipmentStatusRepository
{
    /** @var array<int, Collection<int, ShipmentStatus>> */
    private array $statuses = [];

    /** @var array<int, array<int, list<int>>> */
    private array $edges = [];

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * Every status a company has, in display order, including inactive ones —
     * a shipment already sitting in a status a company has since retired must
     * still render with its proper name.
     *
     * @return Collection<int, ShipmentStatus>
     */
    public function all(?int $companyId = null): Collection
    {
        $companyId ??= $this->tenantContext->requireCompanyId();

        return $this->statuses[$companyId] ??= ShipmentStatus::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->ordered()
            ->get()
            ->keyBy(fn (ShipmentStatus $status): int => (int) $status->getKey());
    }

    /**
     * The statuses a clerk may choose from — active ones only.
     *
     * @return Collection<int, ShipmentStatus>
     */
    public function selectable(?int $companyId = null): Collection
    {
        return $this->all($companyId)->filter(fn (ShipmentStatus $status): bool => $status->is_active)->values();
    }

    public function byCode(string $code, ?int $companyId = null): ?ShipmentStatus
    {
        return $this->all($companyId)->first(fn (ShipmentStatus $status): bool => $status->code === $code);
    }

    /**
     * The company's status carrying a given system behaviour.
     *
     * Throws rather than returning null: every role in
     * ShipmentStatusRole::systemDriven() is protected from deletion, so a
     * missing one means the workflow has been corrupted, and a customs
     * clearance silently not moving its shipment would be worse than a loud
     * failure.
     */
    public function byRole(ShipmentStatusRole $role, ?int $companyId = null): ShipmentStatus
    {
        $status = $this->all($companyId)->first(fn (ShipmentStatus $s): bool => $s->role === $role);

        if ($status === null) {
            throw new RuntimeException("This company has no shipment status for the \"{$role->value}\" role.");
        }

        return $status;
    }

    /** The status a shipment is created in. */
    public function initial(?int $companyId = null): ShipmentStatus
    {
        $status = $this->all($companyId)->first(fn (ShipmentStatus $s): bool => $s->is_initial);

        if ($status === null) {
            throw new RuntimeException('This company has no starting shipment status.');
        }

        return $status;
    }

    /**
     * The statuses a shipment currently in $from may be moved to.
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
        $all = $this->all($companyId);

        return collect($targetIds)
            ->map(fn (int $id): ?ShipmentStatus => $all->get($id))
            ->filter(fn (?ShipmentStatus $status): bool => $status !== null && $status->is_active)
            ->sortBy([['sequence', 'asc'], ['name', 'asc']])
            ->values();
    }

    public function isAllowed(ShipmentStatus $from, ShipmentStatus $to): bool
    {
        return in_array((int) $to->getKey(), $this->edgesFor((int) $from->company_id)[(int) $from->getKey()] ?? [], true);
    }

    /**
     * Codes a shipment can still move on from — what a background poll should
     * bother looking at.
     *
     * @return list<string>
     */
    public function nonTerminalCodes(?int $companyId = null): array
    {
        return $this->all($companyId)
            ->reject(fn (ShipmentStatus $status): bool => $status->is_terminal)
            ->map(fn (ShipmentStatus $status): string => $status->code)
            ->values()
            ->all();
    }

    /** Drops the cached workflow after a settings change. */
    public function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            $this->statuses = [];
            $this->edges = [];

            return;
        }

        unset($this->statuses[$companyId], $this->edges[$companyId]);
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
