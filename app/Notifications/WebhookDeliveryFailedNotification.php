<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WebhookDeliveryFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly WebhookDelivery $delivery, private readonly string $companyName) {}

    /** @param User $notifiable */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->filterChannels(
            $notifiable,
            NotificationType::WebhookDeliveryFailed,
            NotificationType::WebhookDeliveryFailed->channels(),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Webhook delivery failed — {$this->companyName}")
            ->line("A webhook delivery for the \"{$this->delivery->event_type}\" event permanently failed after {$this->delivery->attempts} attempts.")
            ->line("Last response: HTTP {$this->delivery->response_status}.")
            ->line('Check the webhook endpoint configuration under Settings → Webhooks.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'webhook_delivery_id' => $this->delivery->getKey(),
            'event_type' => $this->delivery->event_type,
            'message' => "A webhook delivery for \"{$this->delivery->event_type}\" failed after {$this->delivery->attempts} attempts.",
        ];
    }
}
