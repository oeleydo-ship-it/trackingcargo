<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentPartyRole;
use App\Models\Scopes\CompanyScope;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Models\ShipmentParty;

final readonly class PublicTrackingService
{
    public function __construct(private PublicTrackingFieldPolicy $fields) {}

    /**
     * Look up a shipment for the public tracking page. Runs without a resolved
     * tenant context by design (the caller is anonymous) — it explicitly bypasses
     * CompanyScope for this single, deliberately narrow read, and returns only a
     * sanitized DTO. Never expose the underlying model or any other query built
     * this way outside this method.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $trackingNumber): ?array
    {
        /** @var Shipment|null $shipment */
        $shipment = Shipment::withoutGlobalScope(CompanyScope::class)
            ->with([
                // public_tracking_parties must be in the select: without it the
                // column comes back null and every company silently falls back
                // to the default visibility matrix.
                'company:id,name,public_tracking_parties',
                'trackingEvents' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class)->where('is_public', true),
                // ShipmentParty and Address are both BelongsToCompany too, so
                // the same explicit bypass is needed at every level of this
                // eager load, not just the shipment itself.
                'parties' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class)
                    ->with(['addresses' => fn ($addresses) => $addresses->withoutGlobalScope(CompanyScope::class)]),
                // Packages only for how many boxes of which kind; weights,
                // dimensions and values stay off the public page. A box type
                // or size deleted since booking still describes what was sent,
                // hence withTrashed().
                'packages' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class)
                    ->with(['boxSize' => fn ($size) => $size->withoutGlobalScope(CompanyScope::class)->withTrashed()
                        ->with(['box' => fn ($box) => $box->withoutGlobalScope(CompanyScope::class)->withTrashed()])]),
            ])
            ->where('tracking_number', $trackingNumber)
            ->first();

        if ($shipment === null) {
            return null;
        }

        // The workflow this shipment's branch uses (its own copy or the
        // company default). The repository reads it without tenant scoping,
        // which is what an anonymous visitor with no tenant context needs.
        $statuses = app(ShipmentStatusRepository::class)->forShipment($shipment)->keyBy('code');

        $status = $statuses->get($shipment->status);

        $levels = $this->fields->settings($shipment->company);

        return [
            'tracking_number' => $shipment->tracking_number,
            'carrier' => $shipment->company->name,
            'status' => $shipment->status,
            'status_label' => $status?->name ?? $shipment->status,
            'status_color' => $status?->color ?? 'slate',
            'mode' => $shipment->mode->value,
            'origin_country_code' => $shipment->origin_country_code,
            'destination_country_code' => $shipment->destination_country_code,
            'destination_city' => $shipment->destination_city,
            'last_location' => $shipment->last_location,
            'last_status_at' => $shipment->last_status_at?->toIso8601String(),
            'package_count' => $shipment->package_count,
            'boxes' => $this->boxes($shipment),
            // How much of each party is shown is the company's setting, per
            // field, from Settings -> Public tracking. The linked customer
            // record is never exposed here whatever those are set to.
            'sender' => $this->fields->present(
                $shipment->parties->first(fn (ShipmentParty $party): bool => $party->role === ShipmentPartyRole::Consignor),
                $levels[PublicTrackingFieldPolicy::SENDER],
            ),
            'receiver' => $this->fields->present(
                $shipment->parties->first(fn (ShipmentParty $party): bool => $party->role === ShipmentPartyRole::Consignee),
                $levels[PublicTrackingFieldPolicy::RECEIVER],
            ),
            // Two independent gates: the event may be marked internal when it
            // is recorded, and the company may mark a whole status as one its
            // customers should never see steps for.
            'events' => $shipment->trackingEvents
                ->filter(fn ($event): bool => $statuses->get($event->to_status)?->is_public ?? false)
                ->map(fn ($event): array => [
                    'status' => $event->to_status,
                    'status_label' => $statuses->get($event->to_status)?->name ?? $event->to_status,
                    'location' => $event->location,
                    'description' => $event->description,
                    'occurred_at' => $event->occurred_at->toIso8601String(),
                ])->values()->all(),
        ];
    }

    /**
     * How many boxes of each kind the shipment holds, e.g. 2 x "Box · Jumbo".
     * A package row stands for `pieces` identical boxes. Packages booked with
     * no box from the company's list are counted together as "Custom".
     *
     * @return list<array{label: string, count: int}>
     */
    private function boxes(Shipment $shipment): array
    {
        return $shipment->packages
            ->groupBy(fn (ShipmentPackage $package): string => $package->boxSize
                ? $package->boxSize->box->name.' · '.$package->boxSize->name
                : 'Custom')
            ->map(fn ($packages, string $label): array => [
                'label' => $label,
                'count' => (int) $packages->sum('pieces'),
            ])
            ->values()
            ->all();
    }
}
