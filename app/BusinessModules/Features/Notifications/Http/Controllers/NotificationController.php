<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $query = Notification::forUser($user)
                ->with('analytics')
                ->orderByDesc('created_at');

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

            $notifications = $query->paginate((int) ($request->per_page ?? 20));

            return AdminResponse::paginated(
                $notifications->items(),
                [
                    'current_page' => $notifications->currentPage(),
                    'from' => $notifications->firstItem(),
                    'last_page' => $notifications->lastPage(),
                    'path' => $notifications->path(),
                    'per_page' => $notifications->perPage(),
                    'to' => $notifications->lastItem(),
                    'total' => $notifications->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $notifications->url(1),
                    'last' => $notifications->url($notifications->lastPage()),
                    'prev' => $notifications->previousPageUrl(),
                    'next' => $notifications->nextPageUrl(),
                ]
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('index', $e, $request, trans_message('notifications.load_error'));
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            return AdminResponse::success($this->findNotificationForUser($request, $id)->load('analytics'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('show', $e, $request, trans_message('notifications.load_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->findNotificationForUser($request, $id);
            $notification->markAsRead();

            return AdminResponse::success(
                $notification->fresh(),
                trans_message('notifications.marked_as_read')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('markAsRead', $e, $request, trans_message('notifications.load_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function markAsUnread(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->findNotificationForUser($request, $id);
            $notification->markAsUnread();

            return AdminResponse::success(
                $notification->fresh(),
                trans_message('notifications.marked_as_unread')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('markAsUnread', $e, $request, trans_message('notifications.load_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $updated = Notification::forUser($request->user())
                ->unread()
                ->update(['read_at' => now()]);

            return AdminResponse::success(
                ['count' => $updated],
                trans_message('notifications.mark_all_read', ['count' => (string) $updated])
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('markAllAsRead', $e, $request, trans_message('notifications.load_error'));
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->findNotificationForUser($request, $id);
            $notification->delete();

            return AdminResponse::success(null, trans_message('notifications.delete_success'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('destroy', $e, $request, trans_message('notifications.delete_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            $count = Notification::forUser($user)->unread()->count();
            $byTypeResults = Notification::forUser($user)
                ->unread()
                ->selectRaw("COALESCE(CAST(data AS jsonb)->>'category', 'general') as category, COUNT(*) as count")
                ->groupBy(DB::raw("COALESCE(CAST(data AS jsonb)->>'category', 'general')"))
                ->get();

            return AdminResponse::success([
                'count' => $count,
                'by_type' => $byTypeResults->pluck('count', 'category')->toArray(),
            ]);
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'getUnreadCount',
                $e,
                $request,
                trans_message('notifications.unread_count_error')
            );
        }
    }

    private function findNotificationForUser(Request $request, string $id): Notification
    {
        return Notification::query()
            ->where('id', $id)
            ->forUser($request->user())
            ->firstOrFail();
    }

    private function handleUnexpectedError(
        string $action,
        \Throwable $e,
        Request $request,
        string $message,
        array $context = []
    ): JsonResponse {
        Log::error("[NotificationController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'user_id' => $request->user()?->id,
            ...$context,
        ]);

        return AdminResponse::error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
