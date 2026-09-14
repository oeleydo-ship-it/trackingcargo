<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_statuses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();

            // The value stored on shipments.status. Immutable once shipments
            // reference it; the display name is what companies rename.
            $table->string('code', 40);
            $table->string('name');
            $table->string('color', 24)->default('slate');

            // The system behaviour this status carries, if any. Nullable so a
            // company can add statuses that only humans move shipments into.
            $table->string('role', 24)->nullable();

            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_terminal')->default(false);
            $table->boolean('is_initial')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);

            // At most one status per role, so the modules that resolve a
            // status by role always get exactly one answer. MySQL allows
            // repeated NULLs here, which is what lets a company have many
            // role-less custom statuses.
            $table->unique(['company_id', 'role']);

            $table->index(['company_id', 'is_active', 'sequence']);
        });

        Schema::create('shipment_status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('from_status_id')->constrained('shipment_statuses')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('to_status_id')->constrained('shipment_statuses')->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'from_status_id', 'to_status_id'], 'shipment_status_transitions_edge_unique');
            $table->index(['company_id', 'from_status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_status_transitions');
        Schema::dropIfExists('shipment_statuses');
    }
};
