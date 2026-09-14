<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Enums\DutyType;
use App\Models\CustomsClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreCustomsDutyRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CustomsClearance $clearance */
        $clearance = $this->route('clearance');

        return $this->user()?->can('update', $clearance) ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', new Enum(DutyType::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
