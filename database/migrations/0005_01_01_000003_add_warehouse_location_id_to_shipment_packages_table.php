<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table): void {
            $table->foreignId('warehouse_location_id')->nullable()->after('load_unit_id')
                ->constrained('warehouse_locations')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_location_id');
        });
    }
};
