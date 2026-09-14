<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\DeliveryAssignmentStatus;
use App\Enums\DeliveryAttemptOutcome;
use App\Enums\DocumentCategory;
use App\Enums\ShipmentStatusRole;
use App\Events\DeliveryAttemptRecorded;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryAttempt;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Documents\DocumentService;
use App\Services\Shipments\ShipmentTransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Idempotent by the same shape as Phase 5's WarehouseScanService and Phase
 * 6's CustomsClearanceTransitionService: check idempotency_key first, then a
 * unique (company_id, idempotency_key) DB constraint with a catch-23000
 * refetch under a genuine race. Matters here specifically because a
 * successful attempt both uploads POD documents and completes the shipment —
 * a retried submission from a driver's flaky connection must not re-upload
 * files or attempt to re-complete an already-delivered shipment.
 */
final readonly class DeliveryAttemptService
{
    public function __construct(
        private AuditService $audit,
        private ShipmentTransitionService $shipmentTransitions,
        private DocumentService $documents,
    ) {}

    public function record(
        DeliveryAssignment $assignment,
        DeliveryAttemptOutcome $outcome,
        User $actor,
        string $idempotencyKey,
        array $data,
        ?UploadedFile $signature = null,
        array $photos = [],
    ): DeliveryAttempt {
        $existing = DeliveryAttempt::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($assignment->status !== DeliveryAssignmentStatus::OutForDelivery) {
            throw ValidationException::withMessages(['assignment' => 'The assignment must be out for delivery before an attempt can be recorded.']);
        }

        if ($outcome === DeliveryAttemptOutcome::Succeeded && $signature === null) {
            throw ValidationException::withMessages(['signature' => 'A signature is required to record a successful delivery.']);
        }

        try {
            $attempt = DB::transaction(function () use ($assignment, $outcome, $actor, $idempotencyKey, $data, $signature, $photos): DeliveryAttempt {
                $attempt = $assignment->attempts()->create([
                    'outcome' => $outcome,
                    'attempted_at' => now(),
                    'recipient_name' => $data['recipient_name'] ?? null,
                    'failure_reason' => $data['failure_reason'] ?? null,
                    'reschedule_date' => $data['reschedule_date'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'collected_amount' => $data['collected_amount'] ?? null,
                    'collected_currency' => isset($data['collected_amount']) ? $assignment->shipment->currency : null,
                    'actor_id' => $actor->getKey(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                $this->audit->record("delivery-attempt.{$outcome->value}", $actor, $attempt, newValues: ['outcome' => $outcome->value]);

                if ($outcome === DeliveryAttemptOutcome::Succeeded) {
                    $this->documents->upload($attempt, DocumentCategory::PodSignature, $signature, $actor);

                    foreach ($photos as $photo) {
                        $this->documents->upload($attempt, DocumentCategory::PodPhoto, $photo, $actor);
                    }

                    $assignment->forceFill(['status' => DeliveryAssignmentStatus::Delivered, 'delivered_at' => now()])->save();
                    $this->shipmentTransitions->transitionToRole($assignment->shipment, ShipmentStatusRole::Delivered, $actor);
                }

                return $attempt;
            });
        } catch (QueryException $exception) {
            if ((int) $exception->getCode() !== 23000) {
                throw $exception;
            }

            return DeliveryAttempt::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        event(new DeliveryAttemptRecorded($attempt));

        return $attempt;
    }
}
