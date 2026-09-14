<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use App\Services\Setup\InstallationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class StoreSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Checked before validation, not only in the controller, so an
        // installed site never even reports field errors for this form.
        abort_if(app(InstallationService::class)->isInstalled(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // The same policy My account enforces on a password change. A
            // superadmin password is the key to every tenant, so it is not
            // relaxed here for convenience.
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }
}
