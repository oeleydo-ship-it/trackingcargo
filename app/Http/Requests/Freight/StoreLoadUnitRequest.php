<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Enums\LoadUnitType;
use App\Models\Master;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

final class StoreLoadUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Master $master */
        $master = $this->route('master');

        return $this->user()?->can('update', $master) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'type' => ['required', new Enum(LoadUnitType::class)],
            'unit_number' => ['required', 'string', 'max:40', Rule::unique('load_units', 'unit_number')->where(fn ($query) => $query->where('company_id', $companyId))],
            'seal_number' => ['nullable', 'string', 'max:40'],
        ];
    }
}
