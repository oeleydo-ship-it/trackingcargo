<?php

declare(strict_types=1);

namespace App\Services\Shipments;

use App\Enums\ShipmentStatusRole;
use App\Models\Company;
use App\Models\ShipmentStatus;
use Illuminate\Support\Facades\DB;

/**
 * Gives a new company the default status workflow to start from.
 *
 * This is the set shipments used to be hardcoded to. A company is free to
 * rename, recolour, reorder, extend or prune it afterwards — this only
 * decides where they begin, so that a company created five minutes ago can
 * book and move a shipment without first visiting Settings.
 */
final readonly class ShipmentStatusProvisioner
{
    /**
     * code => [name, colour, role, public?, terminal?, initial?]
     *
     * @return array<string, array{string, string, ShipmentStatusRole, bool, bool, bool}>
     */
    public static function defaults(): array
    {
        return [
            'draft' => ['Draft', 'slate', ShipmentStatusRole::Draft, false, false, true],
            'booked' => ['Booked', 'cyan', ShipmentStatusRole::Booked, true, false, false],
            'received' => ['Received at origin', 'cyan', ShipmentStatusRole::Received, true, false, false],
            'in_transit' => ['In transit', 'blue', ShipmentStatusRole::InTransit, true, false, false],
            'at_customs' => ['At customs', 'amber', ShipmentStatusRole::AtCustoms, true, false, false],
            'out_for_delivery' => ['Out for delivery', 'amber', ShipmentStatusRole::OutForDelivery, true, false, false],
            'delivered' => ['Delivered', 'emerald', ShipmentStatusRole::Delivered, true, true, false],
            'exception' => ['Exception', 'rose', ShipmentStatusRole::Exception, true, false, false],
            'cancelled' => ['Cancelled', 'slate', ShipmentStatusRole::Cancelled, true, true, false],
            'returned' => ['Returned', 'rose', ShipmentStatusRole::Returned, true, true, false],
        ];
    }

    /**
     * The default flow, as code => codes it may move to.
     *
     * @return array<string, list<string>>
     */
    public static function defaultTransitions(): array
    {
        return [
            'draft' => ['booked', 'cancelled'],
            'booked' => ['received', 'cancelled'],
            'received' => ['in_transit', 'exception', 'cancelled'],
            'in_transit' => ['at_customs', 'out_for_delivery', 'exception'],
            'at_customs' => ['in_transit', 'out_for_delivery', 'exception'],
            'out_for_delivery' => ['delivered', 'exception'],
            'exception' => ['in_transit', 'at_customs', 'out_for_delivery', 'cancelled'],
            'delivered' => ['returned'],
            'cancelled' => [],
            'returned' => [],
        ];
    }

    /**
     * Writes the default statuses and transitions for a company that has none.
     *
     * Safe to call more than once: a company that already has statuses is left
     * exactly as it is, so this cannot trample a customised workflow.
     */
    public function provision(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $companyId = (int) $company->getKey();

            $existing = DB::table('shipment_statuses')->where('company_id', $companyId)->exists();

            if ($existing) {
                return;
            }

            $now = now();
            $ids = [];
            $sequence = 0;

            foreach (self::defaults() as $code => [$name, $color, $role, $isPublic, $isTerminal, $isInitial]) {
                $ids[$code] = (int) DB::table('shipment_statuses')->insertGetId([
                    'company_id' => $companyId,
                    'code' => $code,
                    'name' => $name,
                    'color' => $color,
                    'role' => $role->value,
                    'sequence' => $sequence += 10,
                    'is_public' => $isPublic,
                    'is_terminal' => $isTerminal,
                    'is_initial' => $isInitial,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $edges = [];

            foreach (self::defaultTransitions() as $from => $targets) {
                foreach ($targets as $to) {
                    $edges[] = [
                        'company_id' => $companyId,
                        'from_status_id' => $ids[$from],
                        'to_status_id' => $ids[$to],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('shipment_status_transitions')->insert($edges);
        });
    }

    /**
     * The status a newly booked shipment starts in.
     */
    public function initialFor(int $companyId): ShipmentStatus
    {
        return ShipmentStatus::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_initial', true)
            ->orderBy('sequence')
            ->firstOrFail();
    }
}
