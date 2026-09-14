<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table): void {
            $table->foreignId('load_unit_id')->nullable()->after('shipment_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->index(['company_id', 'load_unit_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('load_unit_id');
        });
    }
};
