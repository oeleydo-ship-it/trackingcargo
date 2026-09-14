<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ShipmentDeliveredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Shipment $shipment) {}

    /** @param User $notifiable */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->filterChannels(
            $notifiable,
            NotificationType::ShipmentDelivered,
            NotificationType::ShipmentDelivered->channels(),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Delivered — {$this->shipment->tracking_number}")
            ->line("Your shipment {$this->shipment->tracking_number} has been delivered.")
            ->action('View shipment', route('public.tracking.show', $this->shipment->tracking_number));
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'shipment_id' => $this->shipment->getKey(),
            'tracking_number' => $this->shipment->tracking_number,
            'message' => "Shipment {$this->shipment->tracking_number} was delivered.",
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'shipment_id' => $this->shipment->getKey(),
            'tracking_number' => $this->shipment->tracking_number,
            'message' => "Shipment {$this->shipment->tracking_number} was delivered.",
        ]);
    }
}
