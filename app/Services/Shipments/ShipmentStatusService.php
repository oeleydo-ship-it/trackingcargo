<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Branch;
use App\Models\Shipment;
use App\Models\ShipmentStatus;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edits a company's shipment workflow.
 *
 * The rules enforced here are the ones that keep the rest of the app working
 * once statuses stop being a fixed list: something has to be the starting
 * status, the roles other modules drive shipments into have to keep existing,
 * and a status shipments are actually sitting in cannot vanish underneath
 * them.
 */
final readonly class ShipmentStatusService
{
    private const array AUDITABLE_FIELDS = ['code', 'name', 'color', 'role', 'sequence', 'is_public', 'is_terminal', 'is_initial', 'is_active'];

    public function __construct(
        private TenantContext $tenantContext,
        private ShipmentStatusRepository $statuses,
        private ShipmentStatusProvisioner $provisioner,
        private AuditService $audit,
    ) {}

    /**
     * Adds a status to the company default workflow, or — with a branch_id —
     * to that branch's own workflow, which must already be customised: adding
     * one status to a branch that has none of its own would leave it with a
     * one-status workflow and nowhere for its shipments to be.
     */
    public function create(array $data, User $actor): ShipmentStatus
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($branchId !== null && ! $this->statuses->isCustomised($companyId, $branchId)) {
            throw ValidationException::withMessages([
                'status' => 'This branch uses the company default workflow. Customise it for this branch first, then add statuses to it.',
            ]);
        }

        $scope = $branchId ?? 0;

        return DB::transaction(function () use ($data, $actor, $scope, $branchId): ShipmentStatus {
            $data['code'] = $this->uniqueCode($data['name'], $scope);
            $data['sequence'] ??= ((int) ShipmentStatus::query()->where('scope', $scope)->max('sequence')) + 10;
            $data['branch_id'] = $branchId;

            $status = ShipmentStatus::query()->create(Arr::only($data, [...self::AUDITABLE_FIELDS, 'branch_id']));

            if ($status->is_initial) {
                $this->clearOtherInitials($status);
            }

            $this->syncTransitions($status, $data['transitions_to'] ?? []);

            $this->audit->record('shipment-status.created', $actor, $status, newValues: $status->only(self::AUDITABLE_FIELDS));
            $this->statuses->forget();

            return $status;
        });
    }

    public function update(ShipmentStatus $status, array $data, User $actor): ShipmentStatus
    {
        return DB::transaction(function () use ($status, $data, $actor): ShipmentStatus {
            // The code is what shipments.status already stores, so it is fixed
            // once the status exists — renaming is what the name is for.
            $fields = Arr::only($data, ['name', 'color', 'sequence', 'is_public', 'is_terminal', 'is_initial', 'is_active']);

            $this->assertStillUsable($status, $fields);

            $oldValues = $status->only(array_keys($fields));

            $status->fill($fields)->save();

            if ($status->is_initial) {
                $this->clearOtherInitials($status);
            }

            if (array_key_exists('transitions_to', $data)) {
                $this->syncTransitions($status, $data['transitions_to']);
            }

            $this->audit->record('shipment-status.updated', $actor, $status, oldValues: $oldValues, newValues: $fields);
            $this->statuses->forget();

            return $status;
        });
    }

    public function delete(ShipmentStatus $status, User $actor): void
    {
        if ($status->role?->isSystemDriven()) {
            throw ValidationException::withMessages([
                'status' => "\"{$status->name}\" cannot be removed because {$status->role->systemUse()}. Rename it instead.",
            ]);
        }

        if ($status->is_initial) {
            throw ValidationException::withMessages([
                'status' => "\"{$status->name}\" is where new shipments start. Make another status the starting one first.",
            ]);
        }

        $inUse = $this->shipmentsIn($status);

        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'status' => "{$inUse} shipment".($inUse === 1 ? ' is' : 's are')." currently in \"{$status->name}\". Move them on first, or switch it off instead of deleting it.",
            ]);
        }

        DB::transaction(function () use ($status, $actor): void {
            DB::table('shipment_status_transitions')
                ->where('company_id', $status->company_id)
                ->where(fn ($query) => $query->where('from_status_id', $status->getKey())->orWhere('to_status_id', $status->getKey()))
                ->delete();

            $this->audit->record('shipment-status.deleted', $actor, $status, oldValues: $status->only(self::AUDITABLE_FIELDS));

            $status->delete();
            $this->statuses->forget();
        });
    }

    /**
     * Replaces the set of statuses this one may move to.
     *
     * @param  list<int>  $targetIds
     */
    private function syncTransitions(ShipmentStatus $status, array $targetIds): void
    {
        $companyId = (int) $status->company_id;

        // Only statuses of the same workflow: a transition into another
        // branch's copy would be a move no shipment could ever make.
        $valid = ShipmentStatus::query()
            ->whereKey($targetIds)
            ->whereKeyNot($status->getKey())
            ->where('scope', $status->scope)
            ->pluck('id')
            ->all();

        DB::table('shipment_status_transitions')
            ->where('company_id', $companyId)
            ->where('from_status_id', $status->getKey())
            ->delete();

        if ($valid === []) {
            return;
        }

        $now = now();

        DB::table('shipment_status_transitions')->insert(array_map(
            fn (int $target): array => [
                'company_id' => $companyId,
                'from_status_id' => $status->getKey(),
                'to_status_id' => $target,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $valid,
        ));
    }

    /**
     * Guards the edits that would leave the workflow unusable.
     *
     * @param  array<string, mixed>  $fields
     */
    private function assertStillUsable(ShipmentStatus $status, array $fields): void
    {
        // Switching off a status a module drives shipments into would break
        // that module the next time it fires, not at the moment of the edit.
        if (($fields['is_active'] ?? true) === false && $status->role?->isSystemDriven()) {
            throw ValidationException::withMessages([
                'is_active' => "\"{$status->name}\" cannot be switched off because {$status->role->systemUse()}.",
            ]);
        }

        if (($fields['is_initial'] ?? $status->is_initial) === false && $status->is_initial) {
            throw ValidationException::withMessages([
                'is_initial' => 'Set another status as the starting one rather than leaving the company without one.',
            ]);
        }
    }

    /**
     * Gives a branch its own copy of the company default workflow to edit.
     * Nothing changes for its shipments at the moment of copying: the copy
     * keeps every code, name and transition.
     */
    public function customiseBranch(Branch $branch, User $actor): void
    {
        $companyId = (int) $branch->company_id;

        if ($this->statuses->isCustomised($companyId, (int) $branch->getKey())) {
            throw ValidationException::withMessages(['status' => "{$branch->name} already has its own shipment workflow."]);
        }

        DB::transaction(function () use ($branch, $companyId, $actor): void {
            $this->provisioner->copyToBranch($companyId, (int) $branch->getKey());
            $this->audit->record('shipment-statuses.branch-customised', $actor, $branch);
        });

        $this->statuses->forget();
    }

    /**
     * Returns a branch to the company default workflow and discards its copy.
     *
     * Refused while any of the branch's shipments sits in a status the default
     * does not have — they would be left pointing at a status that no longer
     * exists for them.
     */
    public function resetBranch(Branch $branch, User $actor): void
    {
        $companyId = (int) $branch->company_id;
        $branchId = (int) $branch->getKey();

        if (! $this->statuses->isCustomised($companyId, $branchId)) {
            throw ValidationException::withMessages(['status' => "{$branch->name} already uses the company default workflow."]);
        }

        $defaultCodes = $this->statuses->scopeSet($companyId, 0)->pluck('code')->all();
        $stranded = Shipment::query()->where('branch_id', $branchId)->whereNotIn('status', $defaultCodes)->distinct()->pluck('status')->all();

        if ($stranded !== []) {
            $names = $this->statuses->scopeSet($companyId, $branchId)->whereIn('code', $stranded)->pluck('name')->implode(', ');

            throw ValidationException::withMessages([
                'status' => "Shipments in {$branch->name} are in statuses the company default does not have ({$names}). Move them to another status first.",
            ]);
        }

        DB::transaction(function () use ($branch, $companyId, $branchId, $actor): void {
            $ids = DB::table('shipment_statuses')->where('company_id', $companyId)->where('scope', $branchId)->pluck('id');

            DB::table('shipment_status_transitions')
                ->where('company_id', $companyId)
                ->where(fn ($query) => $query->whereIn('from_status_id', $ids)->orWhereIn('to_status_id', $ids))
                ->delete();

            // Removed outright, soft-deleted ones included, so customising the
            // branch again later starts from a clean copy.
            DB::table('shipment_statuses')->whereIn('id', $ids)->delete();

            $this->audit->record('shipment-statuses.branch-reset', $actor, $branch);
        });

        $this->statuses->forget();
    }

    /**
     * Shipments currently sitting in a status, counting only those whose
     * branch actually uses that status's workflow.
     */
    private function shipmentsIn(ShipmentStatus $status): int
    {
        $query = Shipment::query()->where('status', $status->code);

        if ($status->scope > 0) {
            return $query->where('branch_id', $status->scope)->count();
        }

        $customised = $this->statuses->customisedBranchIds((int) $status->company_id);

        return $query
            ->where(fn ($query) => $query->whereNull('branch_id')->when($customised !== [], fn ($query) => $query->orWhereNotIn('branch_id', $customised), fn ($query) => $query->orWhereNotNull('branch_id')))
            ->count();
    }

    private function clearOtherInitials(ShipmentStatus $status): void
    {
        ShipmentStatus::query()
            ->whereKeyNot($status->getKey())
            ->where('scope', $status->scope)
            ->where('is_initial', true)
            ->update(['is_initial' => false]);
    }

    /**
     * Codes are generated rather than typed: they are an internal key that
     * ends up in webhook payloads and the public tracking API, so they should
     * be stable and URL-safe regardless of what the status is called.
     */
    private function uniqueCode(string $name, int $scope): string
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $base = Str::of($name)->slug('_')->limit(34, '')->toString();
        $base = $base === '' ? 'status' : $base;
        $code = $base;
        $suffix = 1;

        // Unique within the one workflow; a branch copy deliberately reuses
        // the default's codes so its shipments keep resolving.
        while (ShipmentStatus::withoutGlobalScopes()->where('company_id', $companyId)->where('scope', $scope)->where('code', $code)->withTrashed()->exists()) {
            $code = $base.'_'.(++$suffix);
        }

        return $code;
    }
}
