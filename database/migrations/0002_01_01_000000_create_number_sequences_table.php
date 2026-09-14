<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('scope_key', 40);
            $table->string('document_type', 40);
            $table->string('period', 20);
            $table->unsignedBigInteger('next_number')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'scope_key', 'document_type', 'period'], 'number_sequences_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};
