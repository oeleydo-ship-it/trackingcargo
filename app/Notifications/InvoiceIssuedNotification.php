<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\NotificationType;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Notifications\NotificationPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvoiceIssuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Invoice $invoice) {}

    /** @param User $notifiable */
    public function via(object $notifiable): array
    {
        return app(NotificationPreferenceService::class)->filterChannels(
            $notifiable,
            NotificationType::InvoiceIssued,
            NotificationType::InvoiceIssued->channels(),
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Invoice {$this->invoice->invoice_number} issued")
            ->line("Invoice {$this->invoice->invoice_number} for {$this->invoice->currency} {$this->invoice->total} has been issued.")
            ->line($this->invoice->due_date !== null ? "Due date: {$this->invoice->due_date->toDateString()}." : 'No due date was set.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->getKey(),
            'invoice_number' => $this->invoice->invoice_number,
            'message' => "Invoice {$this->invoice->invoice_number} ({$this->invoice->currency} {$this->invoice->total}) was issued.",
        ];
    }
}
