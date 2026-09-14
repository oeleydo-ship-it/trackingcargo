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
            $table->string('batch_number_format', 60)->default('BATCH-{branch}-{sequence}')->after('allow_manual_tracking_number');
            $table->unsignedTinyInteger('batch_sequence_padding')->default(5)->after('batch_number_format');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['batch_number_format', 'batch_sequence_padding']);
        });
    }
};
