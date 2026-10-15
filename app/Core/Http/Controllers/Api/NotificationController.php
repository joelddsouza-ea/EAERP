<?php

namespace App\Core\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app staff notifications (header bell): newest 30, unread first.
 */
class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()
            ->orderByRaw('read_at IS NULL DESC')
            ->latest()
            ->limit(30)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                ...$n->data,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]);

        return $this->success([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $items,
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return $this->success(null, 'Marked as read');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return $this->success(null, 'All marked as read');
    }
}
