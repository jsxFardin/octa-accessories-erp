<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The authenticated user's own inbox. Shared by the header bell and the JSON endpoints
 * so those two surfaces cannot drift.
 */
final class Inbox
{
    /**
     * @return array{unread: int, notifications: list<array<string, mixed>>}
     */
    public static function for(User $user, int $limit = 8): array
    {
        return [
            'unread' => $user->unreadNotifications()->count(),
            'notifications' => $user->notifications()
                ->limit($limit)
                ->get()
                ->map(fn (DatabaseNotification $row): array => [
                    'id' => (string) $row->id,
                    'title' => (string) ($row->data['title'] ?? $row->data['action'] ?? 'Notification'),
                    'href' => isset($row->data['href']) ? (string) $row->data['href'] : null,
                    'action' => isset($row->data['action']) ? (string) $row->data['action'] : null,
                    'document_type' => isset($row->data['document_type']) ? (string) $row->data['document_type'] : null,
                    'document_number' => isset($row->data['document_number']) ? (string) $row->data['document_number'] : null,
                    'read_at' => $row->read_at,
                    'created_at' => $row->created_at,
                ])
                ->all(),
        ];
    }
}
