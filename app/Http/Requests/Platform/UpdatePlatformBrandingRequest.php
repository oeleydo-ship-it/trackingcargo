<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlatformBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->hasPermission('system-configuration.manage');
    }

    public function rules(): array
    {
        return [
            'logo' => ['nullable', 'image', 'max:2048', 'prohibits:remove_logo'],
            'remove_logo' => ['sometimes', 'boolean'],
            'favicon' => ['nullable', 'image', 'max:512', 'prohibits:remove_favicon'],
            'remove_favicon' => ['sometimes', 'boolean'],
        ];
    }
}
