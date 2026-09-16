<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch and per-mode tracking-number formats.
 *
 * companies.tracking_number_format stays the fallback every shipment uses.
 * A row here overrides it for one branch, one mode, or one exact combination
 * of the two — e.g. SGFS-CS{sequence} for sea, SGFS-DCA{sequence} for air out
 * of Dubai.
 *
 * `scope` and `mode_key` are NOT NULL twins of the nullable columns, because a
 * unique index treats every NULL as distinct: without them a company could
 * hold two "any branch, any mode" rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_number_formats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('scope')->default(0);
            $table->string('mode', 16)->nullable();
            $table->string('mode_key', 16)->default('*');
            $table->string('format');
            $table->unsignedTinyInteger('sequence_padding')->default(6);
            $table->timestamps();

            $table->unique(['company_id', 'scope', 'mode_key']);
            $table->index(['company_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_number_formats');
    }
};
