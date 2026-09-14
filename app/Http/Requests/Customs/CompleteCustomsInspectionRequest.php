<?php

declare(strict_types=1);

namespace App\Http\Requests\Customs;

use App\Models\CustomsClearance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CompleteCustomsInspectionRequest extends FormRequest
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
            'status' => ['required', Rule::in(['passed', 'failed'])],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
