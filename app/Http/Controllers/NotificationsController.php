<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        $query = AppNotification::query()
            ->where('notifiable_type', $actor::class)
            ->where('notifiable_id', $actor->id)
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $items = $query->limit(100)->get()->map(fn (AppNotification $n) => [
            'id' => $n->id,
            'type' => $n->type,
            'company' => $n->company,
            'title' => $n->title,
            'body' => $n->body,
            'data' => $n->data,
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ]);

        $unreadCount = AppNotification::query()
            ->where('notifiable_type', $actor::class)
            ->where('notifiable_id', $actor->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => $items,
            'meta' => ['unread_count' => $unreadCount],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        $notification = AppNotification::query()
            ->where('notifiable_type', $actor::class)
            ->where('notifiable_id', $actor->id)
            ->find($id);

        if (! $notification) {
            return response()->json(['message' => 'Notification not found.'], 404);
        }

        $notification->read_at = now();
        $notification->save();

        return response()->json(['message' => 'ok']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $actor = $request->user();

        AppNotification::query()
            ->where('notifiable_type', $actor::class)
            ->where('notifiable_id', $actor->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
