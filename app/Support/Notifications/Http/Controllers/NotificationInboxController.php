<?php

declare(strict_types=1);

namespace App\Support\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Notifications\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A user's own inbox. Like profile, these routes carry no permission: the row belongs to
 * the authenticated user, and a `can:` would either lock everyone out or say nothing.
 */
class NotificationInboxController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(Inbox::for($user, limit: 50));
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var DatabaseNotification $row */
        $row = $user->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json(Inbox::for($user));
    }

    public function readAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications->markAsRead();

        return response()->json(Inbox::for($user));
    }
}
