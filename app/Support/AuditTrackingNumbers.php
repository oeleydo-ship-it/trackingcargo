<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\CustomsClearance;
use App\Models\DeliveryAssignment;
use App\Models\RouteLeg;
use App\Models\Shipment;
use App\Models\ShipmentPackage;
use App\Models\ShipmentParty;
use App\Models\TrackingEvent;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Works out which tracking numbers an audit entry is about, so the log can
 * say "SGFS-CS192787" instead of only "Shipment #51".
 *
 * An entry is about a shipment when its subject is that shipment, when its
 * subject belongs to one (a package, a party, a customs clearance…), or when
 * it is a batch entry that recorded the tracking numbers it moved.
 */
final class AuditTrackingNumbers
{
    /** Subjects that hang off a shipment through a `shipment_id` column. */
    private const SHIPMENT_OWNED = [
        ShipmentParty::class,
        ShipmentPackage::class,
        TrackingEvent::class,
        RouteLeg::class,
        CustomsClearance::class,
        DeliveryAssignment::class,
    ];

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, list<string>> tracking numbers, keyed by audit log id
     */
    public static function for(Collection $logs): array
    {
        $shipmentIdByLog = self::shipmentIds($logs);

        $numbers = $shipmentIdByLog === []
            ? []
            : Shipment::query()->withTrashed()->whereKey(array_unique($shipmentIdByLog))->pluck('tracking_number', 'id')->all();

        $result = [];

        foreach ($logs as $log) {
            $shipmentId = $shipmentIdByLog[$log->getKey()] ?? null;

            $result[$log->getKey()] = $shipmentId !== null && isset($numbers[$shipmentId])
                ? [$numbers[$shipmentId]]
                : self::recordedNumbers($log);
        }

        return $result;
    }

    /**
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, int> shipment id, keyed by audit log id
     */
    private static function shipmentIds(Collection $logs): array
    {
        $found = [];

        foreach ($logs->groupBy('subject_type') as $type => $group) {
            $type = (string) $type;

            if ($type === Shipment::class) {
                foreach ($group as $log) {
                    $found[$log->getKey()] = (int) $log->subject_id;
                }

                continue;
            }

            if (! in_array($type, self::SHIPMENT_OWNED, true)) {
                continue;
            }

            $query = $type::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($type), true)) {
                $query->withTrashed();
            }

            $owners = $query->whereKey($group->pluck('subject_id')->filter()->all())->pluck('shipment_id', 'id');

            foreach ($group as $log) {
                if (isset($owners[$log->subject_id])) {
                    $found[$log->getKey()] = (int) $owners[$log->subject_id];
                }
            }
        }

        return $found;
    }

    /**
     * Batch entries carry the numbers they touched in their own values.
     *
     * @return list<string>
     */
    private static function recordedNumbers(AuditLog $log): array
    {
        $numbers = [
            ...(array) ($log->new_values['tracking_numbers'] ?? []),
            ...(array) ($log->old_values['tracking_numbers'] ?? []),
            ...(array) ($log->new_values['applied'] ?? []),
        ];

        return array_values(array_unique(array_filter($numbers, 'is_string')));
    }
}
