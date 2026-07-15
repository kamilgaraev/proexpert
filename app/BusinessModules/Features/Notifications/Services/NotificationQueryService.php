<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\DTOs\NotificationListSnapshot;
use App\BusinessModules\Features\Notifications\DTOs\NotificationMarkAllReadResult;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\Models\User;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NotificationQueryService
{
    public function __construct(
        private readonly NotificationRequestInterfaceResolver $interfaceResolver,
        private readonly NotificationSnapshotTransactionRunner $snapshotTransactionRunner,
        private readonly NotificationInterfaceCursorStore $cursorStore
    ) {}

    public function visibleTo(Request $request): Builder
    {
        $user = $this->authenticatedUser($request);

        $interface = $this->interfaceResolver->resolve($request);
        $organizationId = $this->organizationId($request, $user);

        return $this->visibleFor($user, $interface, $organizationId);
    }

    public function listSnapshot(Request $request, Closure $configureList, int $perPage): NotificationListSnapshot
    {
        return $this->snapshotTransactionRunner->run(function () use ($request, $configureList, $perPage): NotificationListSnapshot {
            $user = $this->authenticatedUser($request);
            $interface = $this->interfaceResolver->resolve($request);
            $listQuery = $this->visibleTo($request)
                ->orderByDesc('created_at')
                ->orderByDesc('id');
            $configureList($listQuery);
            $notifications = $listQuery->paginate($perPage);
            $unreadQuery = $this->onlyUnread($this->visibleTo($request), $request);

            return new NotificationListSnapshot(
                $notifications,
                $this->unreadAggregatesForQuery($unreadQuery),
                $this->snapshotSequenceFor($user, $interface)
            );
        });
    }

    private function snapshotSequenceFor(User $user, NotificationInterface $interface): int
    {
        return $this->cursorStore->latest($user, $interface);
    }

    private function authenticatedUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new DomainException('Authenticated user is required');
        }

        return $user;
    }

    /**
     * @return array{
     *     count: int,
     *     by_category: array<string, int>,
     *     by_notification_type: array<string, int>,
     *     by_type: array<string, int>,
     *     snapshot_sequence: int
     * }
     */
    public function unreadAggregatesTo(Request $request): array
    {
        return $this->snapshotTransactionRunner->run(function () use ($request): array {
            $user = $this->authenticatedUser($request);
            $interface = $this->interfaceResolver->resolve($request);
            $aggregates = $this->unreadAggregatesForQuery(
                $this->onlyUnread($this->visibleTo($request), $request)
            );

            return [
                ...$aggregates,
                'snapshot_sequence' => $this->snapshotSequenceFor($user, $interface),
            ];
        });
    }

    public function visibleFor(
        User $user,
        NotificationInterface $interface,
        ?int $organizationId
    ): Builder {
        $targetScope = $this->targetScope($interface);

        return Notification::query()
            ->forUser($user)
            ->where(function (Builder $query) use ($organizationId): void {
                $query->whereNull('organization_id');

                if ($organizationId !== null) {
                    $query->orWhere('organization_id', $organizationId);
                }
            })
            ->whereHas('targets', $targetScope)
            ->with(['targets' => $targetScope]);
    }

    public function unreadFor(
        User $user,
        NotificationInterface $interface,
        ?int $organizationId
    ): Builder {
        return $this->applyReadStateFor(
            $this->visibleFor($user, $interface, $organizationId),
            $interface,
            false
        );
    }

    public function unreadCountFor(
        User $user,
        NotificationInterface $interface,
        ?int $organizationId
    ): int {
        return $this->unreadFor($user, $interface, $organizationId)->count();
    }

    public function findVisible(Request $request, string $id): Notification
    {
        return $this->visibleTo($request)->whereKey($id)->firstOrFail();
    }

    public function onlyUnread(Builder $query, Request $request): Builder
    {
        return $this->applyReadState($query, $request, false);
    }

    public function onlyRead(Builder $query, Request $request): Builder
    {
        return $this->applyReadState($query, $request, true);
    }

    public function currentTarget(Notification $notification): NotificationTarget
    {
        $target = $notification->getRelation('targets')->first();

        if (! $target instanceof NotificationTarget) {
            throw (new ModelNotFoundException)->setModel(NotificationTarget::class);
        }

        return $target;
    }

    public function markAllAsRead(Request $request): NotificationMarkAllReadResult
    {
        return DB::transaction(function () use ($request): NotificationMarkAllReadResult {
            $user = $this->authenticatedUser($request);
            $interface = $this->interfaceResolver->resolve($request);
            $sequenceCut = $this->snapshotSequenceFor($user, $interface);
            $visibleNotificationIds = $this->visibleFor(
                $user,
                $interface,
                $this->organizationId($request, $user)
            )->select('notifications.id');
            $updated = NotificationTarget::query()
                ->where('interface', $interface->value)
                ->where('sequence', '<=', $sequenceCut)
                ->whereNull('dismissed_at')
                ->whereNull('read_at')
                ->whereIn('notification_id', $visibleNotificationIds)
                ->update(['read_at' => now()]);

            return new NotificationMarkAllReadResult($updated, $sequenceCut);
        });
    }

    /**
     * @return array{
     *     count: int,
     *     by_category: array<string, int>,
     *     by_notification_type: array<string, int>,
     *     by_type: array<string, int>
     * }
     */
    private function unreadAggregatesForQuery(Builder $unreadQuery): array
    {
        $unreadQuery->without('targets');
        $categoryExpression = "COALESCE(NULLIF({$this->jsonDataValueExpression('category')}, ''), NULLIF(notification_type, ''), 'general')";
        $typeExpression = "COALESCE(NULLIF({$this->jsonDataValueExpression('type')}, ''), NULLIF(type, ''), NULLIF(notification_type, ''), 'general')";
        $notificationTypeExpression = "COALESCE(NULLIF(notification_type, ''), NULLIF({$this->jsonDataValueExpression('notification_type')}, ''), NULLIF({$this->jsonDataValueExpression('category')}, ''), 'general')";

        $byCategory = (clone $unreadQuery)
            ->selectRaw("{$categoryExpression} as category, COUNT(*) as count")
            ->groupBy(DB::raw($categoryExpression))
            ->get()
            ->pluck('count', 'category')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
        $byType = (clone $unreadQuery)
            ->selectRaw("{$typeExpression} as business_type, COUNT(*) as count")
            ->groupBy(DB::raw($typeExpression))
            ->get()
            ->pluck('count', 'business_type')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();
        $byNotificationType = (clone $unreadQuery)
            ->selectRaw("{$notificationTypeExpression} as notification_type, COUNT(*) as count")
            ->groupBy(DB::raw($notificationTypeExpression))
            ->get()
            ->pluck('count', 'notification_type')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'count' => (clone $unreadQuery)->count(),
            'by_category' => $byCategory,
            'by_notification_type' => $byNotificationType,
            'by_type' => $byType,
        ];
    }

    private function applyReadState(Builder $query, Request $request, bool $read): Builder
    {
        $interface = $this->interfaceResolver->resolve($request);

        return $this->applyReadStateFor($query, $interface, $read);
    }

    private function applyReadStateFor(
        Builder $query,
        NotificationInterface $interface,
        bool $read
    ): Builder {

        return $query->whereHas('targets', static function (Builder $targetQuery) use ($interface, $read): void {
            $targetQuery
                ->where('interface', $interface->value)
                ->whereNull('dismissed_at')
                ->when(
                    $read,
                    static fn (Builder $builder): Builder => $builder->whereNotNull('read_at'),
                    static fn (Builder $builder): Builder => $builder->whereNull('read_at')
                );
        });
    }

    private function targetScope(NotificationInterface $interface): callable
    {
        return static fn (Builder $targetQuery): Builder => $targetQuery
            ->where('interface', $interface->value)
            ->whereNull('dismissed_at');
    }

    private function jsonDataValueExpression(string $key): string
    {
        if (DB::getDriverName() === 'pgsql') {
            return "CAST(data AS jsonb)->>'{$key}'";
        }

        return "json_extract(data, '$.{$key}')";
    }

    private function organizationId(Request $request, User $user): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $user->current_organization_id;

        return is_numeric($organizationId) ? (int) $organizationId : null;
    }
}
