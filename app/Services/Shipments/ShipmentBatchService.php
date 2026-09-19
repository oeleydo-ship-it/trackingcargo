<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\BatchStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Shipment;
use App\Models\ShipmentBatch;
use App\Models\ShipmentStatus;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Numbering\NumberSequenceService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ShipmentBatchService
{
    public function __construct(
        private TenantContext $tenantContext,
        private NumberSequenceService $sequences,
        private TrackingNumberFormatter $numbers,
        private ShipmentTransitionService $transitions,
        private ShipmentStatusRepository $statuses,
        private AuditService $audit,
    ) {}

    public function create(array $data, User $actor): ShipmentBatch
    {
        $companyId = $this->tenantContext->requireCompanyId();

        return DB::transaction(function () use ($data, $actor, $companyId): ShipmentBatch {
            $company = Company::query()->findOrFail($companyId);
            $branch = Branch::query()->findOrFail($data['branch_id']);

            $batch = ShipmentBatch::query()->create([
                'branch_id' => $branch->getKey(),
                'batch_number' => $this->allocateBatchNumber($company, $branch),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => BatchStatus::Open,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->record('batch.created', $actor, $batch, newValues: $batch->only(['branch_id', 'batch_number', 'reference']));

            return $batch;
        });
    }

    public function update(ShipmentBatch $batch, array $data, User $actor): ShipmentBatch
    {
        $fields = array_intersect_key($data, array_flip(['reference', 'notes', 'status']));
        $oldValues = $batch->only(array_keys($fields));

        $batch->fill($fields)->save();

        $this->audit->record('batch.updated', $actor, $batch, oldValues: $oldValues, newValues: $fields);

        return $batch;
    }

    /**
     * Adds shipments to a batch.
     *
     * A shipment may only join a batch in its own branch: the batch's number is
     * allocated from that branch's sequence, and branch-scoped staff bulk-update
     * through it. Re-adding a shipment already in the batch is a no-op.
     *
     * @param  list<int>  $shipmentIds
     * @return int the number of shipments actually moved into the batch
     */
    public function addShipments(ShipmentBatch $batch, array $shipmentIds, User $actor): int
    {
        $this->assertOpen($batch);

        $shipments = Shipment::query()->whereKey($shipmentIds)->get();

        $foreign = $shipments->firstWhere(fn (Shipment $shipment): bool => $shipment->branch_id !== $batch->branch_id);

        if ($foreign !== null) {
            throw ValidationException::withMessages([
                'shipments' => "Shipment {$foreign->tracking_number} belongs to a different branch than this batch.",
            ]);
        }

        $moved = $shipments->reject(fn (Shipment $shipment): bool => $shipment->batch_id === $batch->getKey());

        if ($moved->isEmpty()) {
            return 0;
        }

        Shipment::query()->whereKey($moved->modelKeys())->update(['batch_id' => $batch->getKey()]);

        $this->audit->record('batch.shipments-added', $actor, $batch, newValues: [
            'tracking_numbers' => $moved->pluck('tracking_number')->all(),
        ]);

        return $moved->count();
    }

    public function removeShipment(ShipmentBatch $batch, Shipment $shipment, User $actor): void
    {
        $this->assertOpen($batch);

        if ($shipment->batch_id !== $batch->getKey()) {
            throw ValidationException::withMessages(['shipments' => 'That shipment is not in this batch.']);
        }

        $shipment->forceFill(['batch_id' => null])->save();

        $this->audit->record('batch.shipments-removed', $actor, $batch, oldValues: ['tracking_numbers' => [$shipment->tracking_number]]);
    }

    /**
     * Soft-deletes a batch, ungrouping its shipments first.
     *
     * The batch_id FK is nullOnDelete, but a soft delete never reaches the
     * database's delete rule — without this the members would keep pointing at
     * a row that no longer resolves, showing as batched with nowhere to go.
     */
    public function delete(ShipmentBatch $batch, User $actor): void
    {
        DB::transaction(function () use ($batch, $actor): void {
            $batch->shipments()->update(['batch_id' => null]);
            $batch->delete();

            $this->audit->record('batch.deleted', $actor, $batch);
        });
    }

    /**
     * Moves every eligible shipment in the batch to $to.
     *
     * Shipments whose current status does not permit the transition are skipped
     * and reported rather than failing the whole run: a batch of fifty in which
     * two are already delivered should still advance the other forty-eight.
     *
     * Deliberately not wrapped in one transaction. Each transition commits on
     * its own inside ShipmentTransitionService, which then dispatches a webhook
     * and may notify the customer — side effects an outer rollback could not
     * take back. Every shipment still goes through that one choke point, so each
     * gets its tracking event, audit entry and webhook exactly as a single
     * update would.
     *
     * @return array{applied: list<string>, skipped: array<string, string>}
     */
    public function bulkTransition(
        ShipmentBatch $batch,
        ShipmentStatus $to,
        User $actor,
        ?string $location = null,
        ?string $description = null,
        bool $isPublic = true,
    ): array {
        $applied = [];
        $skipped = [];

        foreach ($batch->shipments()->orderBy('id')->get() as $shipment) {
            $from = $this->statuses->statusOf($shipment);

            if ($from === null || ! $this->statuses->isAllowed($from, $to)) {
                $skipped[$shipment->tracking_number] = $from === null
                    ? "unknown status {$shipment->status}"
                    : "can't move from {$from->name} to {$to->name}";

                continue;
            }

            $this->transitions->transition($shipment, $to, $actor, $location, $description, $isPublic);
            $applied[] = $shipment->tracking_number;
        }

        $this->audit->record('batch.bulk-transitioned', $actor, $batch, newValues: [
            'to_status' => $to->code,
            'applied' => $applied,
            'skipped' => array_keys($skipped),
        ]);

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function assertOpen(ShipmentBatch $batch): void
    {
        if ($batch->status !== BatchStatus::Open) {
            throw ValidationException::withMessages([
                'shipments' => 'This batch is closed. Reopen it before changing which shipments it holds.',
            ]);
        }
    }

    private function allocateBatchNumber(Company $company, Branch $branch): string
    {
        $format = $this->numbers->batchFormat($company);
        $period = $this->numbers->period($format);
        $sequence = $this->sequences->next('shipment-batch', (int) $branch->getKey(), $period);

        $batchNumber = $this->numbers->render($format, $company, $branch, $sequence, $this->numbers->batchPadding($company));

        // Batch numbers are printed and scanned alongside tracking numbers, so
        // they are held to the same character set. See ShipmentService for why
        // a stored format can still be unusable.
        if (! $this->numbers->isUrlSafe($batchNumber)) {
            throw ValidationException::withMessages([
                'batch_number' => "The batch number pattern produced \"{$batchNumber}\", which is not usable. Fix the format under Settings → Batches.",
            ]);
        }

        return $batchNumber;
    }
}
