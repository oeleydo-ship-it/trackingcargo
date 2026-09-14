<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Enums\InspectionType;
use App\Models\CustomsClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreCustomsInspectionRequest extends FormRequest
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
            'type' => ['required', new Enum(InspectionType::class)],
            'scheduled_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
