<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Shipper" was ambiguous: on freight documents the sender is the consignor,
 * and readers kept taking "shipper" to mean the carrier moving the cargo.
 * The role is renamed to the formal term the paperwork uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('shipment_parties')
            ->where('role', 'shipper')
            ->update(['role' => 'consignor']);
    }

    public function down(): void
    {
        DB::table('shipment_parties')
            ->where('role', 'consignor')
            ->update(['role' => 'shipper']);
    }
};
