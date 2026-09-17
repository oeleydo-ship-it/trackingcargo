<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-company control over how much of the sender and receiver the public
     * tracking page shows. One JSON column rather than eight boolean columns:
     * the shape is a fixed matrix of role x field, always read and written as a
     * whole through PublicTrackingFieldPolicy, and never queried against.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('public_tracking_parties')->nullable()->after('allow_manual_tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('public_tracking_parties');
        });
    }
};
