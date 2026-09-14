<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('masters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('master_number', 40);
            $table->string('mode', 10);
            $table->string('status', 24)->default('open');

            // Air conveyance identity.
            $table->string('carrier_code', 10)->nullable();
            $table->string('flight_number', 10)->nullable();
            $table->char('origin_airport', 3)->nullable();
            $table->char('destination_airport', 3)->nullable();

            // Sea conveyance identity.
            $table->string('shipping_line')->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('voyage_number', 20)->nullable();
            $table->string('origin_port')->nullable();
            $table->string('destination_port')->nullable();

            $table->timestamp('scheduled_departure_at')->nullable();
            $table->timestamp('scheduled_arrival_at')->nullable();
            $table->timestamp('actual_departure_at')->nullable();
            $table->timestamp('actual_arrival_at')->nullable();

            $table->unsignedInteger('package_count')->default(0);
            $table->decimal('weight_kg', 10, 3)->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'master_number']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('masters');
    }
};
