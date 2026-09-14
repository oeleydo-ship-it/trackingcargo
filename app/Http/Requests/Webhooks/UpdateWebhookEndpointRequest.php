<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use App\Enums\WebhookEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('webhookEndpoint')) ?? false;
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:500', 'starts_with:https://'],
            'description' => ['nullable', 'string', 'max:255'],
            'event_types' => ['required', 'array', 'min:1'],
            'event_types.*' => [Rule::in(['*', ...array_map(fn (WebhookEventType $type): string => $type->value, WebhookEventType::cases())])],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
