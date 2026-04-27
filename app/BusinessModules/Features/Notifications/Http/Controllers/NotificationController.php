<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\Builder;
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
                if ($request->string('filter')->toString() === 'unread') {
                    $query->unread();
                } elseif ($request->string('filter')->toString() === 'read') {
                    $query->read();
                }
            } elseif ($request->has('unread')) {
                $query->unread();
            } elseif ($request->has('read')) {
                $request->boolean('read') ? $query->read() : $query->unread();
            }

            if ($request->filled('notification_type')) {
                $query->byType($request->string('notification_type')->toString());
            } elseif ($request->filled('category')) {
                $this->applyCategoryFilter($query, $request->string('category')->toString());
            } elseif ($request->filled('type')) {
                $this->applyNotificationTypeOrBusinessTypeFilter($query, $request->string('type')->toString());
            }

            if ($request->filled('business_type')) {
                $this->applyDataValueFilter($query, 'type', $request->string('business_type')->toString());
            } elseif ($request->filled('data_type')) {
                $this->applyDataValueFilter($query, 'type', $request->string('data_type')->toString());
            }

            if ($request->filled('priority')) {
                $query->byPriority($request->string('priority')->toString());
            }

            if ($request->filled('project_id')) {
                $this->applyDataValueFilter($query, 'project_id', $request->string('project_id')->toString());
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

    public function unread(Request $request): JsonResponse
    {
        $request->merge(['filter' => 'unread']);

        return $this->index($request);
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
            $byCategoryResults = Notification::forUser($user)
                ->unread()
                ->selectRaw(
                    "COALESCE(NULLIF(CAST(data AS jsonb)->>'category', ''), NULLIF(notification_type, ''), 'general') as category, COUNT(*) as count"
                )
                ->groupBy(DB::raw("COALESCE(NULLIF(CAST(data AS jsonb)->>'category', ''), NULLIF(notification_type, ''), 'general')"))
                ->get();

            $byTypeResults = Notification::forUser($user)
                ->unread()
                ->selectRaw(
                    "COALESCE(NULLIF(CAST(data AS jsonb)->>'type', ''), NULLIF(type, ''), NULLIF(notification_type, ''), 'general') as business_type, COUNT(*) as count"
                )
                ->groupBy(DB::raw("COALESCE(NULLIF(CAST(data AS jsonb)->>'type', ''), NULLIF(type, ''), NULLIF(notification_type, ''), 'general')"))
                ->get();

            $byNotificationTypeResults = Notification::forUser($user)
                ->unread()
                ->selectRaw(
                    "COALESCE(NULLIF(notification_type, ''), NULLIF(CAST(data AS jsonb)->>'notification_type', ''), NULLIF(CAST(data AS jsonb)->>'category', ''), 'general') as notification_type, COUNT(*) as count"
                )
                ->groupBy(DB::raw("COALESCE(NULLIF(notification_type, ''), NULLIF(CAST(data AS jsonb)->>'notification_type', ''), NULLIF(CAST(data AS jsonb)->>'category', ''), 'general')"))
                ->get();

            return AdminResponse::success([
                'count' => $count,
                'by_category' => $byCategoryResults->pluck('count', 'category')->toArray(),
                'by_notification_type' => $byNotificationTypeResults->pluck('count', 'notification_type')->toArray(),
                'by_type' => $byTypeResults->pluck('count', 'business_type')->toArray(),
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

    private function applyCategoryFilter(Builder $query, string $category): void
    {
        $query->where(function (Builder $nested) use ($category): void {
            $nested->where('notification_type', $category)
                ->orWhereRaw("CAST(data AS jsonb)->>'category' = ?", [$category])
                ->orWhereRaw("CAST(data AS jsonb)->>'notification_type' = ?", [$category]);
        });
    }

    private function applyNotificationTypeOrBusinessTypeFilter(Builder $query, string $type): void
    {
        $query->where(function (Builder $nested) use ($type): void {
            $nested->where('notification_type', $type)
                ->orWhereRaw("CAST(data AS jsonb)->>'type' = ?", [$type]);
        });
    }

    private function applyDataValueFilter(Builder $query, string $key, string $value): void
    {
        $query->whereRaw("CAST(data AS jsonb)->>'{$key}' = ?", [$value]);
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
