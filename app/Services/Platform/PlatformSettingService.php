<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Mail\PlatformTestMail;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes the single platform-wide settings row. Kept separate from
 * per-company settings (CompanyController etc.) because none of this is
 * tenant data: it configures the platform itself — its branding, its
 * outgoing mailer, its payment gateway — for every company at once.
 */
final readonly class PlatformSettingService
{
    private const string LOGO_DISK = 'public';

    public function __construct(private AuditService $audit) {}

    public function current(): PlatformSetting
    {
        return PlatformSetting::current();
    }

    public function updateGeneral(array $data, User $actor): PlatformSetting
    {
        return $this->apply($data, ['site_name', 'support_email', 'default_timezone', 'default_currency'], 'platform-settings.general-updated', $actor);
    }

    /**
     * @param  array{logo?: ?UploadedFile, favicon?: ?UploadedFile, remove_logo?: bool, remove_favicon?: bool}  $data
     */
    public function updateBranding(array $data, User $actor): PlatformSetting
    {
        $settings = $this->current();
        $fields = [];

        if (! empty($data['remove_logo'])) {
            $this->deleteFile($settings->logo_path);
            $fields['logo_path'] = null;
        } elseif ($data['logo'] instanceof UploadedFile) {
            $this->deleteFile($settings->logo_path);
            $fields['logo_path'] = $this->storeImage($data['logo']);
        }

        if (! empty($data['remove_favicon'])) {
            $this->deleteFile($settings->favicon_path);
            $fields['favicon_path'] = null;
        } elseif ($data['favicon'] instanceof UploadedFile) {
            $this->deleteFile($settings->favicon_path);
            $fields['favicon_path'] = $this->storeImage($data['favicon']);
        }

        if ($fields === []) {
            return $settings;
        }

        $settings->fill($fields)->save();

        $this->audit->record('platform-settings.branding-updated', $actor, $settings, newValues: $fields);

        return $settings;
    }

    public function updateSmtp(array $data, User $actor): PlatformSetting
    {
        return $this->apply(
            $data,
            ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_address', 'smtp_from_name'],
            'platform-settings.smtp-updated',
            $actor,
            secret: ['smtp_password'],
        );
    }

    /**
     * Sends a real message through the mailer that config()->applyMail() in
     * AppServiceProvider builds from the stored SMTP settings, so a bad host
     * or a rejected login surfaces here instead of on the next customer
     * notification. Fails loudly: the caller turns the thrown transport
     * exception into a form error.
     */
    public function sendTestEmail(string $to, User $actor): void
    {
        Mail::to($to)->send(new PlatformTestMail);

        $this->audit->record('platform-settings.smtp-test-sent', $actor, $this->current(), newValues: ['to' => $to]);
    }

    public function updateStripe(array $data, User $actor): PlatformSetting
    {
        $publishable = $data['stripe_publishable_key'] ?? $this->current()->stripe_publishable_key;
        $secret = ! empty($data['stripe_secret_key']) ? $data['stripe_secret_key'] : $this->current()->stripe_secret_key;
        if ($publishable && $secret && str_contains($publishable, '_live_') !== str_contains($secret, '_live_')) {
            throw ValidationException::withMessages(['stripe_publishable_key' => 'The publishable and secret keys must both use test mode or both use live mode.']);
        }

        return $this->apply(
            $data,
            ['stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret'],
            'platform-settings.stripe-updated',
            $actor,
            secret: ['stripe_secret_key', 'stripe_webhook_secret'],
        );
    }

    /**
     * @param  list<string>  $fields
     * @param  list<string>  $secret  fields whose values are recorded as
     *                                changed rather than logged in the clear
     */
    private function apply(array $data, array $fields, string $action, User $actor, array $secret = []): PlatformSetting
    {
        $settings = $this->current();

        $values = array_intersect_key($data, array_flip($fields));

        // A blank field on a secret means "leave it as is" rather than
        // "clear it" — the form never redisplays a stored secret, so an
        // untouched password input always submits empty.
        foreach ($secret as $field) {
            if (($values[$field] ?? '') === '') {
                unset($values[$field]);
            }
        }

        $settings->fill($values)->save();

        $this->audit->record($action, $actor, $settings, newValues: $this->redact($values, $secret));

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $secret
     * @return array<string, mixed>
     */
    private function redact(array $values, array $secret): array
    {
        foreach ($secret as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = '[changed]';
            }
        }

        return $values;
    }

    private function storeImage(UploadedFile $file): string
    {
        return $file->storeAs('branding', Str::uuid()->toString().'.'.$file->getClientOriginalExtension(), self::LOGO_DISK);
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null) {
            Storage::disk(self::LOGO_DISK)->delete($path);
        }
    }
}
