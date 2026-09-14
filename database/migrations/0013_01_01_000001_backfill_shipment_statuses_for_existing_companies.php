<?php

declare(strict_types=1);

use App\Models\Company;
use App\Services\Shipments\ShipmentStatusProvisioner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives every company that already exists the status set its shipments have
 * been using all along, so nothing changes for them on the day statuses
 * became editable.
 *
 * The codes written here are the same strings already stored in
 * shipments.status, which is what keeps existing shipments pointing at a real
 * status row without touching the shipments table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $provisioner = app(ShipmentStatusProvisioner::class);

        Company::query()->withoutGlobalScopes()->withTrashed()->cursor()
            ->each(fn (Company $company) => $provisioner->provision($company));
    }

    public function down(): void
    {
        DB::table('shipment_status_transitions')->delete();
        DB::table('shipment_statuses')->delete();
    }
};
