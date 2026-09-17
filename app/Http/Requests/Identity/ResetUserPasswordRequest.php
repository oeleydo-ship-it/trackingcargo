<?php

declare(strict_types=1);

namespace App\Http\Requests\Identity;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class ResetUserPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->route('user');

        return $subject instanceof User && ($this->user()?->can('update', $subject) ?? false);
    }

    public function rules(): array
    {
        return [
            // "password" sets one the administrator hands over; "email" mails
            // the usual reset link so the user picks their own.
            'method' => ['required', Rule::in(['password', 'email'])],
            'password' => ['exclude_unless:method,password', 'required', 'confirmed', Password::min(12)->letters()->numbers()],
        ];
    }
}
