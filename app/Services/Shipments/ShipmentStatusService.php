<?php

declare(strict_types=1);

namespace App\Services\Shipments;

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
        private AuditService $audit,
    ) {}

    public function create(array $data, User $actor): ShipmentStatus
    {
        return DB::transaction(function () use ($data, $actor): ShipmentStatus {
            $data['code'] = $this->uniqueCode($data['name']);
            $data['sequence'] ??= ((int) ShipmentStatus::query()->max('sequence')) + 10;

            $status = ShipmentStatus::query()->create(Arr::only($data, self::AUDITABLE_FIELDS));

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

        $inUse = Shipment::query()->where('status', $status->code)->count();

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

        $valid = ShipmentStatus::query()
            ->whereKey($targetIds)
            ->whereKeyNot($status->getKey())
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

    private function clearOtherInitials(ShipmentStatus $status): void
    {
        ShipmentStatus::query()
            ->whereKeyNot($status->getKey())
            ->where('is_initial', true)
            ->update(['is_initial' => false]);
    }

    /**
     * Codes are generated rather than typed: they are an internal key that
     * ends up in webhook payloads and the public tracking API, so they should
     * be stable and URL-safe regardless of what the status is called.
     */
    private function uniqueCode(string $name): string
    {
        $companyId = $this->tenantContext->requireCompanyId();
        $base = Str::of($name)->slug('_')->limit(34, '')->toString();
        $base = $base === '' ? 'status' : $base;
        $code = $base;
        $suffix = 1;

        while (ShipmentStatus::withoutGlobalScopes()->where('company_id', $companyId)->where('code', $code)->withTrashed()->exists()) {
            $code = $base.'_'.(++$suffix);
        }

        return $code;
    }
}
