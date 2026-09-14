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
            $table->string('tracking_number_format', 60)->default('{company}-{branch}-{sequence}')->after('volumetric_divisor');
            $table->unsignedTinyInteger('tracking_sequence_padding')->default(8)->after('tracking_number_format');
            $table->boolean('allow_manual_tracking_number')->default(false)->after('tracking_sequence_padding');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['tracking_number_format', 'tracking_sequence_padding', 'allow_manual_tracking_number']);
        });
    }
};
