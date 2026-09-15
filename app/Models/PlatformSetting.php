<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The single platform-wide settings row. There is intentionally no company
 * scoping here — see the migration for why — and no factory: a fresh
 * install gets its row lazily, the first time current() is called.
 */
#[Fillable([
    'site_name', 'support_email', 'default_timezone', 'default_currency',
    'registration_enabled', 'registration_requires_approval', 'registration_requires_email_verification',
    'logo_path', 'favicon_path',
    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_address', 'smtp_from_name',
    'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
])]
final class PlatformSetting extends Model
{
    protected $hidden = ['smtp_password', 'stripe_secret_key', 'stripe_webhook_secret'];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'registration_enabled' => 'boolean',
            'registration_requires_approval' => 'boolean',
            'registration_requires_email_verification' => 'boolean',
            // Secrets at rest, encrypted with the app key rather than stored
            // in plain text — this table has no per-tenant access boundary to
            // lean on, unlike company-scoped data.
            'smtp_password' => 'encrypted',
            'stripe_secret_key' => 'encrypted',
            'stripe_webhook_secret' => 'encrypted',
        ];
    }

    /**
     * The one settings row, created on first touch rather than via a seeder
     * so a fresh install never has to remember to run one.
     *
     * Deliberately just "the first row that exists", not
     * firstOrCreate(['id' => 1], ...): `id` is never mass-assignable (it
     * isn't in #[Fillable] — a primary key never should be), so
     * firstOrCreate(['id' => 1]) would silently drop the id from its insert
     * and let the column auto-increment instead. The moment anything else
     * ever advances that counter past 1 — even a rolled-back insert, since
     * MySQL's auto_increment does not roll back — the row would land on id
     * 2 (or higher) and every future call would stop finding it by id=1,
     * silently creating a fresh, empty-defaults row on every single read.
     * Reading "whichever row exists" instead of "the row with id 1" sidesteps
     * that failure mode entirely.
     *
     * The defaults are passed explicitly rather than left to the migration's
     * column defaults: Eloquent's create() never re-selects a freshly
     * inserted row, so a column left out of the insert (relying on its DB
     * default) would come back as null in this very instance — correct only
     * on the next, separate read. Passing them here keeps the row a caller
     * gets back on the very first call as accurate as any later one.
     */
    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([
            'site_name' => 'CargoFlow',
            'default_timezone' => 'UTC',
            'default_currency' => 'USD',
            'registration_enabled' => false,
            'registration_requires_approval' => true,
            'registration_requires_email_verification' => true,
        ]);
    }
}
