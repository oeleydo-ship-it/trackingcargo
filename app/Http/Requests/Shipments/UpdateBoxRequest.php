<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Models\Box;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateBoxRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Box $box */
        $box = $this->route('box');

        return $this->user()?->can('update', $box) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
