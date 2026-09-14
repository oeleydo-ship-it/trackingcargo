<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class SendPlatformTestEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('system-configuration.manage');
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'email', 'max:255'],
        ];
    }
}
