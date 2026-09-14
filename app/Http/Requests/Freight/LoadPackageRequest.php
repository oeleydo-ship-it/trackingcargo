<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Models\LoadUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoadPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LoadUnit $unit */
        $unit = $this->route('unit');

        return $this->user()?->can('update', $unit) ?? false;
    }

    public function rules(): array
    {
        $companyId = $this->user()?->company_id;

        return [
            'package_id' => [
                'required',
                'integer',
                Rule::exists('shipment_packages', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
        ];
    }
}
