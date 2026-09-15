<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a branch run its own copy of the shipment workflow.
 *
 * Rows with no branch are the company default, which every branch uses until
 * someone customises that branch; a customised branch then has a full set of
 * its own rows. `scope` is branch_id-or-0, kept as a real NOT NULL column so
 * the uniqueness rules below hold for the company default too — a unique
 * index treats every NULL as distinct, so (company_id, branch_id, code) alone
 * would let the default set hold duplicate codes.
 *
 * A branch's copy keeps the default's codes, so shipments already sitting in
 * a status keep pointing at a real row when their branch is customised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_statuses', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('company_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedBigInteger('scope')->default(0)->after('branch_id');
        });

        Schema::table('shipment_statuses', function (Blueprint $table): void {
            // New rules first, so company_id never loses the index its foreign
            // key relies on while the old ones are dropped.
            $table->unique(['company_id', 'scope', 'code']);
            $table->unique(['company_id', 'scope', 'role']);
        });

        Schema::table('shipment_statuses', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'code']);
            $table->dropUnique(['company_id', 'role']);
        });
    }

    public function down(): void
    {
        // Branch copies cannot fit the company-wide uniqueness rules again.
        DB::table('shipment_statuses')->where('scope', '>', 0)->delete();

        Schema::table('shipment_statuses', function (Blueprint $table): void {
            $table->unique(['company_id', 'code']);
            $table->unique(['company_id', 'role']);
        });

        Schema::table('shipment_statuses', function (Blueprint $table): void {
            $table->dropUnique(['company_id', 'scope', 'code']);
            $table->dropUnique(['company_id', 'scope', 'role']);
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn('scope');
        });
    }
};
