<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Enums\MasterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class TransitionMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('transition', $this->route('master')) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(MasterStatus::class)],
        ];
    }
}
