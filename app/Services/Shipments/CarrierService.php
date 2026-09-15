<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Models\Carrier;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CarrierService
{
    private const array FIELDS = ['name', 'code', 'modes', 'integration_code', 'website', 'contact_name', 'contact_email', 'contact_phone', 'is_active'];

    public function __construct(private AuditService $audit) {}

    public function create(array $data, User $actor): Carrier
    {
        $carrier = Carrier::query()->create($this->normalize($data));

        $this->audit->record('carrier.created', $actor, $carrier, newValues: $carrier->only(self::FIELDS));

        return $carrier;
    }

    /**
     * Edits a carrier. When its tracking integration changes, the shipments
     * already booked with it follow, since carrier_code is what the tracking
     * poller reads — a carrier edited to use an integration should start being
     * tracked, not only for shipments booked afterwards.
     */
    public function update(Carrier $carrier, array $data, User $actor): Carrier
    {
        return DB::transaction(function () use ($carrier, $data, $actor): Carrier {
            $fields = $this->normalize($data);
            $oldValues = $carrier->only(array_keys($fields));

            $carrier->fill($fields)->save();

            if ($carrier->wasChanged('integration_code')) {
                Shipment::query()->where('carrier_id', $carrier->getKey())->update(['carrier_code' => $carrier->integration_code]);
            }

            $this->audit->record('carrier.updated', $actor, $carrier, oldValues: $oldValues, newValues: $fields);

            return $carrier;
        });
    }

    /**
     * Carriers are switched off rather than deleted: shipments already booked
     * with one keep showing who moved them.
     */
    public function setActive(Carrier $carrier, bool $active, User $actor): Carrier
    {
        $old = $carrier->is_active;
        $carrier->forceFill(['is_active' => $active])->save();

        $this->audit->record('carrier.'.($active ? 'activated' : 'deactivated'), $actor, $carrier, oldValues: ['is_active' => $old], newValues: ['is_active' => $active]);

        return $carrier;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $fields = Arr::only($data, self::FIELDS);

        if (isset($fields['code'])) {
            $fields['code'] = Str::upper(trim((string) $fields['code']));
        }

        // An empty mode list means "any mode", stored as null so the booking
        // form's filter has one thing to check.
        if (array_key_exists('modes', $fields)) {
            $modes = array_values(array_unique(array_filter((array) $fields['modes'])));
            $fields['modes'] = $modes === [] ? null : $modes;
        }

        foreach (['integration_code', 'website', 'contact_name', 'contact_email', 'contact_phone'] as $key) {
            if (array_key_exists($key, $fields) && trim((string) $fields[$key]) === '') {
                $fields[$key] = null;
            }
        }

        return $fields;
    }
}
