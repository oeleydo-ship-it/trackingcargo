<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the standard package sizes need.
 *
 * `pieces` lets one package row stand for several identical pieces — "3 Jumbo
 * boxes" — instead of forcing three rows. Existing rows are one piece each, so
 * every shipment's totals stay exactly as they are.
 *
 * Box sizes become nullable and gain `is_custom` for the sizes that have no
 * fixed dimensions (Odd Size, Crate): the clerk types the measurements on the
 * package itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pieces')->default(1)->after('box_size_id');
        });

        Schema::table('box_sizes', function (Blueprint $table): void {
            $table->boolean('is_custom')->default(false)->after('name');
            $table->decimal('length_cm', 8, 2)->nullable()->change();
            $table->decimal('width_cm', 8, 2)->nullable()->change();
            $table->decimal('height_cm', 8, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('shipment_packages', function (Blueprint $table): void {
            $table->dropColumn('pieces');
        });

        // Custom sizes have no dimensions to put back, so they go before the
        // columns become NOT NULL again.
        DB::table('box_sizes')->where('is_custom', true)->delete();

        Schema::table('box_sizes', function (Blueprint $table): void {
            $table->dropColumn('is_custom');
            $table->decimal('length_cm', 8, 2)->nullable(false)->change();
            $table->decimal('width_cm', 8, 2)->nullable(false)->change();
            $table->decimal('height_cm', 8, 2)->nullable(false)->change();
        });
    }
};
