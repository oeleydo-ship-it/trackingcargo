<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipments;

use App\Models\Box;
use App\Models\BoxSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBoxSizeRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Box $box */
        $box = $this->route('box');

        return $this->user()?->can('update', $box) ?? false;
    }

    public function rules(): array
    {
        /** @var Box $box */
        $box = $this->route('box');
        /** @var BoxSize $size */
        $size = $this->route('size');

        return [
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('box_sizes', 'name')->where(fn ($query) => $query->where('box_id', $box->getKey()))->ignore($size->getKey()),
            ],
            'length_cm' => ['required', 'numeric', 'min:0.01'],
            'width_cm' => ['required', 'numeric', 'min:0.01'],
            'height_cm' => ['required', 'numeric', 'min:0.01'],
        ];
    }
}
