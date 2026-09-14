<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('master_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('unit_number', 40);
            $table->string('seal_number', 40)->nullable();
            $table->string('status', 24)->default('building');
            $table->unsignedInteger('package_count')->default(0);
            $table->decimal('weight_kg', 10, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'unit_number']);
            $table->index(['company_id', 'master_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_units');
    }
};
