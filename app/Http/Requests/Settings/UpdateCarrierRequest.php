<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('carrier')) ?? false;
    }

    public function rules(): array
    {
        return CarrierRules::for($this->user()?->company_id, (int) $this->route('carrier')->getKey());
    }
}
