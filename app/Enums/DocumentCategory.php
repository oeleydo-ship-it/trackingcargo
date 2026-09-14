<?php

declare(strict_types=1);

namespace App\Enums;

enum DocumentCategory: string
{
    case CustomsDeclaration = 'customs_declaration';
    case CommercialInvoice = 'commercial_invoice';
    case PackingList = 'packing_list';
    case CertificateOfOrigin = 'certificate_of_origin';
    case ImportPermit = 'import_permit';
    case PodSignature = 'pod_signature';
    case PodPhoto = 'pod_photo';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CustomsDeclaration => 'Customs declaration',
            self::CommercialInvoice => 'Commercial invoice',
            self::PackingList => 'Packing list',
            self::CertificateOfOrigin => 'Certificate of origin',
            self::ImportPermit => 'Import permit',
            self::PodSignature => 'Proof of delivery signature',
            self::PodPhoto => 'Proof of delivery photo',
            self::Other => 'Other',
        };
    }
}
