<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Enums\ShipmentStatusRole;
use Carbon\CarbonImmutable;

/**
 * What a CarrierProviderInterface reports back for one shipment. `mappedStatus`
 * is null when the carrier's update is informational only and does not
 * correspond to an internal status change — CarrierTrackingPollService then
 * records nothing rather than forcing a status transition that doesn't exist.
 *
 * It is a role rather than a company status because a carrier integration is
 * shared across every company on the platform and cannot know what any one of
 * them calls its statuses; the poll service resolves the role against the
 * shipment's own company.
 */
final readonly class CarrierTrackingUpdate
{
    public function __construct(
        public string $externalStatus,
        public ?ShipmentStatusRole $mappedStatus,
        public ?string $location,
        public ?string $description,
        public CarbonImmutable $occurredAt,
    ) {}
}
