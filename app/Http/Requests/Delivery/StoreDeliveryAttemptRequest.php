<?php

declare(strict_types=1);

namespace App\Http\Requests\Delivery;

use App\Enums\DeliveryAttemptOutcome;
use App\Models\DeliveryAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class StoreDeliveryAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DeliveryAssignment $assignment */
        $assignment = $this->route('assignment');

        return $this->user()?->can('execute', $assignment) ?? false;
    }

    public function rules(): array
    {
        $isSucceeded = $this->input('outcome') === DeliveryAttemptOutcome::Succeeded->value;
        $isFailed = $this->input('outcome') === DeliveryAttemptOutcome::Failed->value;

        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'outcome' => ['required', new Enum(DeliveryAttemptOutcome::class)],
            'recipient_name' => [$isSucceeded ? 'required' : 'nullable', 'string', 'max:120'],
            'collected_amount' => [$isSucceeded ? 'nullable' : 'prohibited', 'numeric', 'min:0'],
            'signature' => [$isSucceeded ? 'required' : 'prohibited', 'file', 'image', 'max:5120'],
            'photos' => ['nullable', 'array', 'max:5'],
            'photos.*' => ['file', 'image', 'max:5120'],
            'failure_reason' => [$isFailed ? 'required' : 'prohibited', 'string', 'max:255'],
            'reschedule_date' => [$isFailed ? 'nullable' : 'prohibited', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
