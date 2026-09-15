<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The carriers a company hands cargo to — airlines, shipping lines, couriers,
 * trucking firms — as a list the company maintains in Settings.
 *
 * shipments.carrier_code predates this and stays: it names the tracking
 * integration that polls a shipment (config/carriers.php), which is a
 * platform-level concept. A carrier row can point at one of those
 * integrations, and picking the carrier sets carrier_code from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20);
            // Which shipment modes this carrier serves; null means any, so the
            // booking form only filters when a company bothered to say.
            $table->json('modes')->nullable();
            $table->string('integration_code', 40)->nullable();
            $table->string('website')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'name']);
        });

        Schema::table('shipments', function (Blueprint $table): void {
            $table->foreignId('carrier_id')->nullable()->after('mode')->constrained('carriers')->cascadeOnUpdate()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_id');
        });

        Schema::dropIfExists('carriers');
    }
};
