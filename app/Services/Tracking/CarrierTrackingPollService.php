<?php

declare(strict_types=1);

namespace App\Services\Tracking;

use App\Models\Company;
use App\Models\Shipment;
use App\Services\Shipments\ShipmentStatusRepository;
use App\Services\Shipments\ShipmentTransitionService;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * Polls every trackable shipment that has a carrier assigned. A background
 * job (Architecture: "Background jobs must carry the company identifier and
 * restore tenant context before loading tenant models") — iterates company
 * by company, resolving TenantContext before each company's shipment query,
 * exactly like every prior phase's cross-tenant background work.
 *
 * The `shipment.status_changed` webhook and any status-driven notification
 * are dispatched by ShipmentTransitionService itself (the single choke
 * point every status change goes through), not duplicated here.
 */
final readonly class CarrierTrackingPollService
{
    public function __construct(
        private TenantContext $tenantContext,
        private CarrierProviderRegistry $registry,
        private ShipmentTransitionService $transitions,
        private ShipmentStatusRepository $statuses,
        private LoggerInterface $logger,
    ) {}

    public function pollAll(): void
    {
        Company::query()->each(function (Company $company): void {
            $this->tenantContext->resolveCompany((int) $company->getKey());

            try {
                Shipment::query()
                    ->whereNotNull('carrier_code')
                    ->whereIn('status', $this->statuses->nonTerminalCodes((int) $company->getKey()))
                    ->each(fn (Shipment $shipment) => $this->pollOne($shipment));
            } finally {
                $this->tenantContext->forget();
            }
        });
    }

    private function pollOne(Shipment $shipment): void
    {
        $update = $this->registry->resolve($shipment->carrier_code)->poll($shipment);

        if ($update === null || $update->mappedStatus === null) {
            return;
        }

        try {
            $this->transitions->transitionToRole($shipment, $update->mappedStatus, null, $update->location, $update->description, true);
        } catch (ValidationException) {
            // The carrier's reported status is no longer a valid next step for this
            // shipment (e.g. a human already advanced it another way in the
            // meantime) — skip it silently rather than let one stale shipment
            // abort the whole poll run.
            return;
        }

        $this->logger->info('carrier-tracking.polled', [
            'shipment_id' => $shipment->getKey(),
            'carrier_code' => $shipment->carrier_code,
            'status' => $update->mappedStatus->value,
        ]);
    }
}
