<?php

declare(strict_types=1);

namespace App\Http\Requests\Notifications;

use App\Enums\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Validator;

final class UpdateNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'notification_type' => ['required', new Enum(NotificationType::class)],
            'channel' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $type = NotificationType::tryFrom((string) $this->input('notification_type'));

            if ($type !== null && ! in_array($this->input('channel'), $type->channels(), true)) {
                $validator->errors()->add('channel', "\"{$this->input('channel')}\" is not a valid channel for {$type->label()}.");
            }
        });
    }
}
