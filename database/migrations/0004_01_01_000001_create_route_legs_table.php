<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('route_legs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('master_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('mode', 10);
            $table->string('origin_location');
            $table->string('destination_location');
            $table->string('status', 24)->default('planned');
            $table->timestamp('scheduled_departure_at')->nullable();
            $table->timestamp('scheduled_arrival_at')->nullable();
            $table->timestamp('actual_departure_at')->nullable();
            $table->timestamp('actual_arrival_at')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'sequence']);
            $table->index(['company_id', 'shipment_id']);
            $table->index(['company_id', 'master_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('route_legs');
    }
};
