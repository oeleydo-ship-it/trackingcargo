<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public workspace sign-up, switched on and off by a superadmin.
 *
 * Off by default: an existing install must not start accepting strangers'
 * workspaces just because it was upgraded. Approval is on by default for the
 * same reason — turning sign-up on should not, on its own, also mean anyone
 * who fills in the form gets a working workspace.
 *
 * Email verification is on by default too, but can be switched off for
 * installs with no mail provider, where a verification email could never
 * arrive and every new sign-up would be stuck on the verify-email page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->boolean('registration_enabled')->default(false)->after('default_currency');
            $table->boolean('registration_requires_approval')->default(true)->after('registration_enabled');
            $table->boolean('registration_requires_email_verification')->default(true)->after('registration_requires_approval');
        });
    }

    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table): void {
            $table->dropColumn(['registration_enabled', 'registration_requires_approval', 'registration_requires_email_verification']);
        });
    }
};
