<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_card_tiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('rate_card_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->decimal('min_weight_kg', 10, 3);
            $table->decimal('max_weight_kg', 10, 3)->nullable();
            $table->decimal('price_per_kg', 12, 2);
            $table->timestamps();

            $table->index(['company_id', 'rate_card_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_card_tiers');
    }
};
