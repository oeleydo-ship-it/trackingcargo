<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single-row, platform-wide settings table — no company_id, because
     * every field here (site branding, the outgoing mailer, the payment
     * gateway credentials) applies across every tenant, not to one of them.
     * PlatformSetting::current() enforces the single row by always reading
     * and writing id=1.
     */
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('site_name')->default('CargoFlow');
            $table->string('support_email')->nullable();
            $table->string('default_timezone')->default('UTC');
            $table->string('default_currency', 3)->default('USD');
            $table->string('logo_path')->nullable();
            $table->string('favicon_path')->nullable();

            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_encryption', 10)->nullable();
            $table->string('smtp_from_address')->nullable();
            $table->string('smtp_from_name')->nullable();

            $table->string('stripe_publishable_key')->nullable();
            $table->text('stripe_secret_key')->nullable();
            $table->text('stripe_webhook_secret')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
