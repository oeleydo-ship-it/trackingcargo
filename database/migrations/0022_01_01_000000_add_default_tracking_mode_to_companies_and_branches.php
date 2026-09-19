<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // The numbering method the booking form starts with.
            $table->string('default_tracking_mode', 10)->default('auto')->after('allow_manual_tracking_number');
        });

        Schema::table('branches', function (Blueprint $table): void {
            // Null follows the company's default.
            $table->string('default_tracking_mode', 10)->nullable()->after('tracking_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn('default_tracking_mode');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('default_tracking_mode');
        });
    }
};
