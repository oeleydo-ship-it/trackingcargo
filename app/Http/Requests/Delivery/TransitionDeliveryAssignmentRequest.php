<?php

declare(strict_types=1);

namespace App\Http\Requests\Delivery;

use App\Enums\DeliveryAssignmentStatus;
use App\Models\DeliveryAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class TransitionDeliveryAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DeliveryAssignment $assignment */
        $assignment = $this->route('assignment');

        return $this->user()?->can('execute', $assignment) ?? false;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(DeliveryAssignmentStatus::class)],
        ];
    }
}
