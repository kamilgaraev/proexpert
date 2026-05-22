<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
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

            $meta = [
                'current_page' => $notifications->currentPage(),
                'from' => $notifications->firstItem(),
                'last_page' => $notifications->lastPage(),
                'path' => $notifications->path(),
                'per_page' => $notifications->perPage(),
                'to' => $notifications->lastItem(),
                'total' => $notifications->total(),
            ];
            $links = [
                'first' => $notifications->url(1),
                'last' => $notifications->url($notifications->lastPage()),
                'prev' => $notifications->previousPageUrl(),
                'next' => $notifications->nextPageUrl(),
            ];

            if ($this->isMobileRequest($request)) {
                return MobileResponse::success($notifications->items(), null, Response::HTTP_OK, array_merge($meta, [
                    'links' => $links,
                ]));
            }

            return AdminResponse::paginated(
                $notifications->items(),
                $meta,
                null,
                Response::HTTP_OK,
                null,
                $links
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError('index', $e, $request, trans_message('notifications.load_error'));
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            return $this->success($request, $this->findNotificationForUser($request, $id)->load('analytics'));
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
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

            return $this->success(
                $request,
                $notification->fresh(),
                trans_message('notifications.marked_as_read')
            );
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
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

            return $this->success(
                $request,
                $notification->fresh(),
                trans_message('notifications.marked_as_unread')
            );
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
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

            return $this->success(
                $request,
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

            return $this->success($request, null, trans_message('notifications.delete_success'));
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
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
                    "COALESCE(NULLIF({$this->jsonDataValueExpression('category')}, ''), NULLIF(notification_type, ''), 'general') as category, COUNT(*) as count"
                )
                ->groupBy(DB::raw("COALESCE(NULLIF({$this->jsonDataValueExpression('category')}, ''), NULLIF(notification_type, ''), 'general')"))
                ->get();

            $byTypeResults = Notification::forUser($user)
                ->unread()
                ->selectRaw(
                    "COALESCE(NULLIF({$this->jsonDataValueExpression('type')}, ''), NULLIF(type, ''), NULLIF(notification_type, ''), 'general') as business_type, COUNT(*) as count"
                )
                ->groupBy(DB::raw("COALESCE(NULLIF({$this->jsonDataValueExpression('type')}, ''), NULLIF(type, ''), NULLIF(notification_type, ''), 'general')"))
                ->get();

            $byNotificationTypeResults = Notification::forUser($user)
                ->unread()
                ->selectRaw(
                    "COALESCE(NULLIF(notification_type, ''), NULLIF({$this->jsonDataValueExpression('notification_type')}, ''), NULLIF({$this->jsonDataValueExpression('category')}, ''), 'general') as notification_type, COUNT(*) as count"
                )
                ->groupBy(DB::raw("COALESCE(NULLIF(notification_type, ''), NULLIF({$this->jsonDataValueExpression('notification_type')}, ''), NULLIF({$this->jsonDataValueExpression('category')}, ''), 'general')"))
                ->get();

            return $this->success($request, [
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
                ->orWhereRaw($this->jsonDataValueExpression('category') . ' = ?', [$category])
                ->orWhereRaw($this->jsonDataValueExpression('notification_type') . ' = ?', [$category]);
        });
    }

    private function applyNotificationTypeOrBusinessTypeFilter(Builder $query, string $type): void
    {
        $query->where(function (Builder $nested) use ($type): void {
            $nested->where('notification_type', $type)
                ->orWhereRaw($this->jsonDataValueExpression('type') . ' = ?', [$type]);
        });
    }

    private function applyDataValueFilter(Builder $query, string $key, string $value): void
    {
        $query->whereRaw($this->jsonDataValueExpression($key) . ' = ?', [$value]);
    }

    private function jsonDataValueExpression(string $key): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "CAST(data AS jsonb)->>'{$key}'";
        }

        return "json_extract(data, '$.{$key}')";
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

        return $this->error($request, $message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function success(Request $request, mixed $data = null, ?string $message = null, int $code = Response::HTTP_OK): JsonResponse
    {
        if ($this->isMobileRequest($request)) {
            return MobileResponse::success($data, $message, $code);
        }

        return AdminResponse::success($data, $message, $code);
    }

    private function error(Request $request, string $message, int $code): JsonResponse
    {
        if ($this->isMobileRequest($request)) {
            return MobileResponse::error($message, $code);
        }

        return AdminResponse::error($message, $code);
    }

    private function isMobileRequest(Request $request): bool
    {
        return str_starts_with(trim($request->path(), '/'), 'api/v1/mobile/notifications');
    }
}
