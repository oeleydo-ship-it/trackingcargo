<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->decimal('collected_amount', 12, 2)->nullable()->after('notes');
            $table->char('collected_currency', 3)->nullable()->after('collected_amount');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_attempts', function (Blueprint $table): void {
            $table->dropColumn(['collected_amount', 'collected_currency']);
        });
    }
};
