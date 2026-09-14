<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent only from Settings -> Platform's "Send test email" button, so a
 * platform admin can confirm a newly entered SMTP host/port/credential set
 * actually delivers before it becomes the mailer every company's
 * notifications go through.
 */
final class PlatformTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): self
    {
        return $this->subject('CargoFlow SMTP test')
            ->text('emails.platform-smtp-test');
    }
}
