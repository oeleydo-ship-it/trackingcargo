<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_packages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedInteger('package_number');
            $table->string('barcode', 60);
            $table->string('description')->nullable();
            $table->decimal('weight_kg', 10, 3);
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->decimal('volumetric_weight_kg', 10, 3)->default(0);
            $table->decimal('declared_value', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'barcode']);
            $table->unique(['shipment_id', 'package_number']);
            $table->index(['company_id', 'shipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_packages');
    }
};
