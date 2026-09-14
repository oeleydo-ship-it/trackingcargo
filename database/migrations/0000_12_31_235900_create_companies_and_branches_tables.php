<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->char('default_currency', 3)->default('USD');
            $table->string('status', 24)->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('tracking_prefix', 10);
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->char('country_code', 2);
            $table->string('city', 120);
            $table->text('address')->nullable();
            $table->string('timezone', 64)->default('UTC');
            $table->string('status', 24)->default('active')->index();
            $table->boolean('is_head_office')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'tracking_prefix']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
