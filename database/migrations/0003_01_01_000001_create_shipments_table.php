<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('tracking_number', 40)->unique();
            $table->string('mode', 20);
            $table->string('status', 24)->default('draft');
            $table->char('origin_country_code', 2)->nullable();
            $table->char('destination_country_code', 2)->nullable();
            $table->string('destination_city')->nullable();
            $table->decimal('declared_weight_kg', 10, 3)->default(0);
            $table->decimal('volumetric_weight_kg', 10, 3)->default(0);
            $table->decimal('chargeable_weight_kg', 10, 3)->default(0);
            $table->unsignedInteger('package_count')->default(0);
            $table->char('currency', 3);
            $table->decimal('declared_value', 12, 2)->nullable();
            $table->string('last_location')->nullable();
            $table->timestamp('last_status_at')->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'branch_id']);
            $table->index(['company_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
