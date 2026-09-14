<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentPartyRole;
use App\Models\Scopes\CompanyScope;
use App\Models\Shipment;
use App\Models\ShipmentParty;
use App\Models\ShipmentStatus;

final readonly class PublicTrackingService
{
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
                'company:id,name',
                'trackingEvents' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class)->where('is_public', true),
                // ShipmentParty and Address are both BelongsToCompany too, so
                // the same explicit bypass is needed at every level of this
                // eager load, not just the shipment itself.
                'parties' => fn ($query) => $query->withoutGlobalScope(CompanyScope::class)
                    ->with(['addresses' => fn ($addresses) => $addresses->withoutGlobalScope(CompanyScope::class)]),
            ])
            ->where('tracking_number', $trackingNumber)
            ->first();

        if ($shipment === null) {
            return null;
        }

        // Statuses are per-company rows, and this runs for an anonymous
        // visitor with no tenant context, so the company's set is read
        // explicitly rather than through the scoped repository.
        $statuses = ShipmentStatus::withoutGlobalScopes()
            ->where('company_id', $shipment->company_id)
            ->get()
            ->keyBy('code');

        $status = $statuses->get($shipment->status);

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
            // Name and city/country only — never the full address, phone,
            // email, or the linked customer, matching how public tracking
            // pages elsewhere in the industry show "who" and "roughly
            // where" without handing out someone else's contact details or
            // exact street address to an anonymous visitor.
            'sender' => $this->publicParty($shipment->parties->first(fn (ShipmentParty $party): bool => $party->role === ShipmentPartyRole::Consignor)),
            'receiver' => $this->publicParty($shipment->parties->first(fn (ShipmentParty $party): bool => $party->role === ShipmentPartyRole::Consignee)),
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
     * @return array{name: string, city: ?string, country_code: ?string}|null
     */
    private function publicParty(?ShipmentParty $party): ?array
    {
        if ($party === null) {
            return null;
        }

        $address = $party->addresses->first();

        return [
            'name' => $this->maskName($party->name),
            'city' => $address?->city,
            'country_code' => $address?->country_code,
        ];
    }

    /**
     * "John Smith" -> "John S."; a single-word name has its middle
     * characters starred out instead, since there is no surname to drop.
     * Never returns the name typed at booking verbatim — an anonymous
     * visitor gets enough to recognise a shipment as theirs, not a full
     * identity to read off a public page.
     */
    private function maskName(string $name): string
    {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return '';
        }

        $first = array_shift($words);

        if ($words === []) {
            $length = mb_strlen($first);

            return $length <= 2 ? $first : mb_substr($first, 0, 1).str_repeat('*', $length - 2).mb_substr($first, -1);
        }

        return $first.' '.implode(' ', array_map(
            static fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)).'.',
            $words,
        ));
    }
}
