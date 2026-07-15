<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Services;

use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Models\NotificationTarget;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

final class NotificationQueryService
{
    public function __construct(
        private readonly NotificationRequestInterfaceResolver $interfaceResolver
    ) {}

    public function visibleTo(Request $request): Builder
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new DomainException('Authenticated user is required');
        }

        $interface = $this->interfaceResolver->resolve($request);
        $organizationId = $this->organizationId($request, $user);
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

    public function markAllAsRead(Request $request): int
    {
        $interface = $this->interfaceResolver->resolve($request);
        $visibleNotificationIds = $this->visibleTo($request)->select('notifications.id');

        return NotificationTarget::query()
            ->where('interface', $interface->value)
            ->whereNull('dismissed_at')
            ->whereNull('read_at')
            ->whereIn('notification_id', $visibleNotificationIds)
            ->update(['read_at' => now()]);
    }

    private function applyReadState(Builder $query, Request $request, bool $read): Builder
    {
        $interface = $this->interfaceResolver->resolve($request);

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

    private function organizationId(Request $request, User $user): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $user->current_organization_id;

        return is_numeric($organizationId) ? (int) $organizationId : null;
    }
}
