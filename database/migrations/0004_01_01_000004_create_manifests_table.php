<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manifests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('master_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('manifest_number', 40);
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_id', 'manifest_number']);
            $table->index(['company_id', 'master_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manifests');
    }
};
