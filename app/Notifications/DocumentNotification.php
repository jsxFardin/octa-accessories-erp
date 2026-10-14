<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One document notification: always the in-app inbox, and mail as well once the installation
 * has a real mailer. With `log` or `array` the mail would go nowhere, so it is not attempted;
 * the dedupe key keeps the two channels in step because they are one notification.
 *
 * @phpstan-type Payload array{
 *     document_type: string,
 *     document_id: int,
 *     document_number: string|null,
 *     action: string,
 *     href: string,
 *     title: string,
 *     body?: string,
 *     dedupe_key: string
 * }
 */
class DocumentNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Payload  $payload
     */
    public function __construct(public readonly array $payload) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        $mailerWorks = ! in_array((string) config('mail.default'), ['log', 'array', ''], true);
        $hasAddress = filled($notifiable->email ?? null);

        return $mailerWorks && $hasAddress ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject($this->payload['title'])
            ->line($this->payload['title']);

        if (filled($this->payload['body'] ?? null)) {
            $message->line((string) $this->payload['body']);
        }

        return $message->action('Open '.$this->payload['document_type'].' '.($this->payload['document_number'] ?? ''), url($this->payload['href']));
    }

    /**
     * @return Payload
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
