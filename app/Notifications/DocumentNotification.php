<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * One database notification. Channel is database only — mail is not production-safe here
 * (MAIL_MAILER=log) and is out of P2-4 scope.
 *
 * @phpstan-type Payload array{
 *     document_type: string,
 *     document_id: int,
 *     document_number: string|null,
 *     action: string,
 *     href: string,
 *     title: string,
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
        return ['database'];
    }

    /**
     * @return Payload
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
