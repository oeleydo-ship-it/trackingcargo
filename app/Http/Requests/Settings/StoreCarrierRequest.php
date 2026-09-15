<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Models\Carrier;
use Illuminate\Foundation\Http\FormRequest;

final class StoreCarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Carrier::class) ?? false;
    }

    public function rules(): array
    {
        return CarrierRules::for($this->user()?->company_id);
    }
}
