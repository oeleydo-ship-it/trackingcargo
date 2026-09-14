<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customs_duties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customs_clearance_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('description', 255);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'customs_clearance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_duties');
    }
};
