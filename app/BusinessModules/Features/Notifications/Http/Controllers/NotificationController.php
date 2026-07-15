<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Services\NotificationPresenter;
use App\BusinessModules\Features\Notifications\Services\NotificationQueryService;
use App\BusinessModules\Features\Notifications\Services\NotificationRequestInterfaceResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationQueryService $queryService,
        private readonly NotificationRequestInterfaceResolver $interfaceResolver,
        private readonly NotificationPresenter $presenter
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $interface = $this->interfaceResolver->resolve($request);
            $defaultPerPage = $interface === NotificationInterface::Customer ? 25 : 20;
            $perPage = max(1, min(100, $request->integer('per_page', $defaultPerPage)));
            $snapshot = $this->queryService->listSnapshot(
                $request,
                function (Builder $query) use ($request): void {
                    $this->applyReadFilter($query, $request);
                    $this->applyListFilters($query, $request);
                },
                $perPage
            );
            $notifications = $snapshot->notifications;
            $items = array_map(
                fn ($notification): array => $interface === NotificationInterface::Customer
                    ? $this->presenter->presentForCustomer($notification)
                    : $this->presenter->present($notification),
                $notifications->items()
            );

            return $this->paginated($request, $items, $notifications, $snapshot->unreadAggregates);
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('index', $e, $request, trans_message('notifications.load_error'));
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->queryService->findVisible($request, $id);

            return $this->success($request, $this->presenter->present($notification));
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('show', $e, $request, trans_message('notifications.load_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function unread(Request $request): JsonResponse
    {
        try {
            $request->merge(['filter' => 'unread']);

            return $this->index($request);
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('unread', $e, $request, trans_message('notifications.load_error'));
        }
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->queryService->findVisible($request, $id);
            $this->queryService->currentTarget($notification)->markAsRead();

            return $this->success(
                $request,
                $this->presenter->present($notification),
                trans_message('notifications.marked_as_read')
            );
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('markAsRead', $e, $request, trans_message('notifications.load_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function markAsUnread(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->queryService->findVisible($request, $id);
            $this->queryService->currentTarget($notification)->markAsUnread();

            return $this->success(
                $request,
                $this->presenter->present($notification),
                trans_message('notifications.marked_as_unread')
            );
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('markAsUnread', $e, $request, trans_message('notifications.load_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        try {
            $updated = $this->queryService->markAllAsRead($request);

            return $this->success(
                $request,
                ['count' => $updated],
                trans_message('notifications.mark_all_read', ['count' => (string) $updated])
            );
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('markAllAsRead', $e, $request, trans_message('notifications.load_error'));
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $notification = $this->queryService->findVisible($request, $id);
            $this->queryService->currentTarget($notification)->dismiss();

            return $this->success($request, null, trans_message('notifications.delete_success'));
        } catch (ModelNotFoundException) {
            return $this->error($request, trans_message('notifications.not_found'), Response::HTTP_NOT_FOUND);
        } catch (Throwable $e) {
            return $this->handleUnexpectedError('destroy', $e, $request, trans_message('notifications.delete_error'), [
                'notification_id' => $id,
            ]);
        }
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        try {
            return $this->success($request, $this->queryService->unreadAggregatesTo($request));
        } catch (Throwable $e) {
            return $this->handleUnexpectedError(
                'getUnreadCount',
                $e,
                $request,
                trans_message('notifications.unread_count_error')
            );
        }
    }

    private function applyReadFilter(Builder $query, Request $request): void
    {
        if ($request->has('filter')) {
            if ($request->string('filter')->toString() === 'unread') {
                $this->queryService->onlyUnread($query, $request);
            } elseif ($request->string('filter')->toString() === 'read') {
                $this->queryService->onlyRead($query, $request);
            }

            return;
        }

        if ($request->has('unread')) {
            if (
                $this->interfaceResolver->resolve($request) === NotificationInterface::Customer
                && ! $request->boolean('unread')
            ) {
                return;
            }

            $this->queryService->onlyUnread($query, $request);
        } elseif ($request->has('read')) {
            $request->boolean('read')
                ? $this->queryService->onlyRead($query, $request)
                : $this->queryService->onlyUnread($query, $request);
        }
    }

    private function applyListFilters(Builder $query, Request $request): void
    {
        if ($request->filled('notification_type')) {
            $query->byType($request->string('notification_type')->toString());
        } elseif ($request->filled('event_type')) {
            $query->byType($request->string('event_type')->toString());
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
    }

    private function applyCategoryFilter(Builder $query, string $category): void
    {
        $query->where(function (Builder $nested) use ($category): void {
            $nested->where('notification_type', $category)
                ->orWhereRaw($this->jsonDataValueExpression('category').' = ?', [$category])
                ->orWhereRaw($this->jsonDataValueExpression('notification_type').' = ?', [$category]);
        });
    }

    private function applyNotificationTypeOrBusinessTypeFilter(Builder $query, string $type): void
    {
        $query->where(function (Builder $nested) use ($type): void {
            $nested->where('notification_type', $type)
                ->orWhereRaw($this->jsonDataValueExpression('type').' = ?', [$type]);
        });
    }

    private function applyDataValueFilter(Builder $query, string $key, string $value): void
    {
        $query->whereRaw($this->jsonDataValueExpression($key).' = ?', [$value]);
    }

    private function jsonDataValueExpression(string $key): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "CAST(data AS jsonb)->>'{$key}'";
        }

        return "json_extract(data, '$.{$key}')";
    }

    private function paginated(
        Request $request,
        array $items,
        LengthAwarePaginator $notifications,
        array $unreadAggregates
    ): JsonResponse {
        $meta = [
            'current_page' => $notifications->currentPage(),
            'from' => $notifications->firstItem(),
            'last_page' => $notifications->lastPage(),
            'path' => $notifications->path(),
            'per_page' => $notifications->perPage(),
            'to' => $notifications->lastItem(),
            'total' => $notifications->total(),
            'unread_count' => $unreadAggregates['count'],
            'unread_by_category' => $unreadAggregates['by_category'],
            'unread_by_notification_type' => $unreadAggregates['by_notification_type'],
            'unread_by_type' => $unreadAggregates['by_type'],
        ];
        $links = [
            'first' => $notifications->url(1),
            'last' => $notifications->url($notifications->lastPage()),
            'prev' => $notifications->previousPageUrl(),
            'next' => $notifications->nextPageUrl(),
        ];

        return match ($this->interfaceResolver->resolve($request)) {
            NotificationInterface::Admin => AdminResponse::paginated(
                $items,
                $meta,
                links: $links
            ),
            NotificationInterface::Lk => LandingResponse::paginated(
                $items,
                $meta,
                links: $links
            ),
            NotificationInterface::Mobile => MobileResponse::success(
                $items,
                code: Response::HTTP_OK,
                meta: array_merge($meta, ['links' => $links])
            ),
            NotificationInterface::Customer => $this->customerPaginated(
                $request,
                $items,
                $unreadAggregates
            ),
        };
    }

    private function customerPaginated(
        Request $request,
        array $items,
        array $unreadAggregates
    ): JsonResponse {
        $filters = $request->query();

        return CustomerResponse::success([
            'items' => $items,
            'meta' => [
                'organization_id' => $this->organizationId($request),
                'unread_count' => $unreadAggregates['count'],
                'total' => count($items),
                'filters' => $filters,
            ],
        ], trans_message('customer.notifications_loaded'));
    }

    private function organizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        return is_numeric($organizationId) ? (int) $organizationId : null;
    }

    private function handleUnexpectedError(
        string $action,
        Throwable $e,
        Request $request,
        string $message,
        array $context = []
    ): JsonResponse {
        Log::error("[NotificationController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id')
                ?? $request->user()?->current_organization_id,
            ...$context,
        ]);

        return $this->error($request, $message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function success(
        Request $request,
        mixed $data = null,
        ?string $message = null,
        int $code = Response::HTTP_OK
    ): JsonResponse {
        return match ($this->interfaceResolver->resolve($request)) {
            NotificationInterface::Admin => AdminResponse::success($data, $message, $code),
            NotificationInterface::Lk => LandingResponse::success($data, $message, $code),
            NotificationInterface::Mobile => MobileResponse::success($data, $message, $code),
            NotificationInterface::Customer => CustomerResponse::success($data, $message, $code),
        };
    }

    private function error(Request $request, string $message, int $code): JsonResponse
    {
        return match ($this->interfaceResolver->resolve($request)) {
            NotificationInterface::Admin => AdminResponse::error($message, $code),
            NotificationInterface::Lk => LandingResponse::error($message, $code),
            NotificationInterface::Mobile => MobileResponse::error($message, $code),
            NotificationInterface::Customer => CustomerResponse::error($message, $code),
        };
    }
}
