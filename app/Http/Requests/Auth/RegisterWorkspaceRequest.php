<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Services\Platform\WorkspaceRegistrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Checked before validation so a closed sign-up form never reports
        // field errors, and so it is indistinguishable from a missing route.
        abort_unless(app(WorkspaceRegistrationService::class)->isOpen(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'city' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            // The same policy My account enforces on a password change.
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
            'terms' => ['accepted'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'country_code' => strtoupper(trim((string) $this->input('country_code'))),
        ]);
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'An account with this email already exists. Sign in instead, or reset your password.',
            'terms.accepted' => 'Please confirm you are authorised to create this workspace.',
        ];
    }
}
