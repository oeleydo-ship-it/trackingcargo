<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Models\Box;
use Illuminate\Foundation\Http\FormRequest;

final class StoreBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Box::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
