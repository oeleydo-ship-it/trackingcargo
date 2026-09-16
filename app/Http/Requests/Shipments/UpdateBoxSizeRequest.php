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

    protected function prepareForValidation(): void
    {
        // Normalised so exclude_if below compares against a plain 1/0 rather
        // than whatever shape the checkbox arrived in.
        $this->merge(['is_custom' => $this->boolean('is_custom') ? 1 : 0]);
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
            // A custom size (Odd Size, Crate) is measured on each package, so it
            // carries no dimensions of its own.
            'is_custom' => ['sometimes', 'boolean'],
            'length_cm' => ['exclude_if:is_custom,1', 'required', 'numeric', 'min:0.01'],
            'width_cm' => ['exclude_if:is_custom,1', 'required', 'numeric', 'min:0.01'],
            'height_cm' => ['exclude_if:is_custom,1', 'required', 'numeric', 'min:0.01'],
        ];
    }
}
