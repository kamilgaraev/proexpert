<?php

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Notification::forUser($user)
            ->with('analytics')
            ->orderBy('created_at', 'desc');

        if ($request->has('filter')) {
            if ($request->filter === 'unread') {
                $query->unread();
            } elseif ($request->filter === 'read') {
                $query->whereNotNull('read_at');
            }
        } elseif ($request->has('unread')) {
            $query->unread();
        }

        if ($request->has('type')) {
            $query->byType($request->type);
        }

        if ($request->has('priority')) {
            $query->byPriority($request->priority);
        }

        $notifications = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->forUser($user)
            ->with('analytics')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $notification,
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->forUser($user)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $notification,
        ]);
    }

    public function markAsUnread(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->forUser($user)
            ->firstOrFail();

        $notification->markAsUnread();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as unread',
            'data' => $notification,
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $updated = Notification::forUser($user)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json([
            'success' => true,
            'message' => "Marked {$updated} notifications as read",
            'count' => $updated,
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        $notification = Notification::where('id', $id)
            ->forUser($user)
            ->firstOrFail();

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted',
        ]);
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Notification::forUser($user)->unread()->count();
        
        $byTypeResults = Notification::forUser($user)
            ->unread()
            ->selectRaw("COALESCE(data->>'category', 'general') as category, COUNT(*) as count")
            ->groupBy(DB::raw("COALESCE(data->>'category', 'general')"))
            ->get();
        
        $byType = $byTypeResults->pluck('count', 'category')->toArray();

        return response()->json([
            'success' => true,
            'count' => $count,
            'by_type' => $byType,
        ]);
    }
}

