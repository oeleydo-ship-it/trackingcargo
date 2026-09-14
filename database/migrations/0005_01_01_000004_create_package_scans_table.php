<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('shipment_package_id')->constrained('shipment_packages')->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('scan_type', 20);
            $table->foreignId('from_location_id')->nullable()->constrained('warehouse_locations')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('warehouse_locations')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('load_unit_id')->nullable()->constrained('load_units')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100);
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'idempotency_key']);
            $table->index(['company_id', 'shipment_package_id', 'occurred_at']);
            $table->index(['company_id', 'warehouse_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_scans');
    }
};
