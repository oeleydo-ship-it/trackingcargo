<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The parties named on a shipment's freight documents.
 *
 * These are the cargo-side parties only. The carrier — the company actually
 * moving the box — is not one of them: it is recorded on the shipment itself
 * as carrier_code, since it is a company-level integration rather than a
 * per-shipment contact.
 */
enum ShipmentPartyRole: string
{
    /** The sender: the person or business the cargo originates from. */
    case Consignor = 'consignor';

    /** The receiver named to take delivery in the destination country. */
    case Consignee = 'consignee';

    /** An extra contact notified about the shipment, party to neither end. */
    case Notify = 'notify';

    public function label(): string
    {
        return match ($this) {
            self::Consignor => 'Consignor',
            self::Consignee => 'Consignee',
            self::Notify => 'Notify party',
        };
    }

    /**
     * The plain-English word for the role, for screens and documents where
     * the formal freight term alone would not land.
     */
    public function description(): string
    {
        return match ($this) {
            self::Consignor => 'Sender',
            self::Consignee => 'Receiver',
            self::Notify => 'Additional contact',
        };
    }

    /**
     * The kind of address this party's location represents: cargo is picked up
     * from the consignor and delivered to the consignee.
     */
    public function addressType(): AddressType
    {
        return match ($this) {
            self::Consignor => AddressType::Pickup,
            self::Consignee => AddressType::Delivery,
            self::Notify => AddressType::Other,
        };
    }
}
