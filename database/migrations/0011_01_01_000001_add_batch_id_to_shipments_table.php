<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            // nullOnDelete: removing a batch ungroups its shipments rather than
            // taking them with it.
            $table->foreignId('batch_id')->nullable()->after('branch_id')->constrained('shipment_batches')->cascadeOnUpdate()->nullOnDelete();

            $table->index(['company_id', 'batch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropForeign(['batch_id']);
            $table->dropIndex(['company_id', 'batch_id']);
            $table->dropColumn('batch_id');
        });
    }
};
