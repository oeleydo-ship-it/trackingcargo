<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The behaviours the system itself attaches to a shipment status.
 *
 * Companies define their own statuses — names, colours, ordering, and which
 * transitions are legal. But several modules have to *drive* a shipment to a
 * particular state on their own: customs puts it at customs and releases it,
 * dispatch sends it out for delivery, a completed delivery marks it delivered.
 * Those modules cannot look a status up by a name the customer is free to
 * change, so each one resolves it by role instead.
 *
 * A role is therefore the stable anchor underneath a company's own label: a
 * status called "Sa Customs" or "Zoll" still carries the AtCustoms role, and
 * customs keeps working. A status with no role at all is a purely custom one
 * that only humans move shipments into.
 */
enum ShipmentStatusRole: string
{
    case Draft = 'draft';
    case Booked = 'booked';
    case Received = 'received';
    case InTransit = 'in_transit';
    case AtCustoms = 'at_customs';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Exception = 'exception';
    case Cancelled = 'cancelled';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Booked => 'Booked',
            self::Received => 'Received at origin',
            self::InTransit => 'In transit',
            self::AtCustoms => 'At customs',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Exception => 'Exception',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    /**
     * Roles another module moves a shipment into by itself. A company may
     * rename and recolour these, and may choose which transitions reach them,
     * but may not remove them — deleting the AtCustoms status would leave
     * CustomsClearanceService with nowhere to put a shipment it just opened a
     * clearance for.
     *
     * @return list<self>
     */
    public static function systemDriven(): array
    {
        return [self::Draft, self::AtCustoms, self::InTransit, self::OutForDelivery, self::Delivered, self::Exception];
    }

    public function isSystemDriven(): bool
    {
        return in_array($this, self::systemDriven(), true);
    }

    /**
     * Why this role cannot be deleted, phrased for the settings screen.
     */
    public function systemUse(): ?string
    {
        return match ($this) {
            self::Draft => 'every shipment starts here when it is booked',
            self::AtCustoms => 'customs clearances move shipments here',
            self::InTransit => 'customs releases shipments back to here',
            self::OutForDelivery => 'dispatching a delivery run moves shipments here',
            self::Delivered => 'a completed delivery moves shipments here',
            self::Exception => 'a rejected clearance or failed delivery moves shipments here',
            default => null,
        };
    }
}
