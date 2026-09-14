<?php

declare(strict_types=1);

namespace App\Http\Requests\Freight;

use App\Enums\LoadUnitStatus;
use App\Models\LoadUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class TransitionLoadUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LoadUnit $unit */
        $unit = $this->route('unit');

        return $this->user()?->can('update', $unit) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(LoadUnitStatus::class)],
        ];
    }
}
