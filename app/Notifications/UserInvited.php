<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $acceptUrl, private readonly string $companyName)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You've been invited to {$this->companyName} on CargoFlow")
            ->line("You have been invited to join {$this->companyName} on CargoFlow.")
            ->action('Accept invitation', $this->acceptUrl)
            ->line('This invitation link expires in 7 days.');
    }
}
