<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob;
use App\BusinessModules\Features\AIAssistant\Models\RagGlobalIndexEvent;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\Organization;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Throwable;

final class GlobalRagQueue
{
    private const TYPES = ['knowledge' => ['knowledge_article'],
        'estimate_reference' => ['estimate_template', 'estimate_library_item', 'estimate_catalog_item', 'normative_rate']];

    public function __construct(private readonly RagSourceRegistry $registry, private readonly Dispatcher $bus, private readonly LoggerInterface $logger) {}

    public static function supports(string $sourceType, string $entityType, string|int $entityId): bool
    {
        $metadata = \App\BusinessModules\Features\AIAssistant\Services\AssistantExtendedDomainRegistry::class;
        $extended = $metadata::values('globalCatalogEntities') + $metadata::values('organizationNullableCatalogs')
            + $metadata::values('publicCatalogEntities') + $metadata::values('globalFanoutEntities');
        if (isset($extended[$entityType]) && ($metadata::values('entityDefinitions')[$entityType][0] ?? null) === $sourceType) {
            return (bool) preg_match('/^[1-9]\d*$/D', (string) $entityId) || Str::isUuid((string) $entityId);
        }
        return in_array($entityType, self::TYPES[$sourceType] ?? [], true)
            && (bool) preg_match('/^[1-9]\d*$/D', (string) $entityId)
            && filter_var((string) $entityId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    public function queueAfterCommit(string $sourceType, string $entityType, string|int $entityId): void
    {
        $this->record($sourceType, $entityType, $entityId);
    }

    public function record(string $sourceType, string $entityType, string|int $entityId, int $initialCursor = 0): RagGlobalIndexEvent
    {
        $this->validate($sourceType, $entityType, $entityId);
        return DB::transaction(function () use ($sourceType, $entityType, $entityId, $initialCursor): RagGlobalIndexEvent {
            $identity = ['source_type' => $sourceType, 'entity_type' => $entityType, 'entity_id' => (string) $entityId];
            $inserted = RagGlobalIndexEvent::query()->insertOrIgnore($identity + ['revision' => 1,
                'after_organization_id' => max(0, $initialCursor), 'status' => RagGlobalIndexEvent::STATUS_QUEUED,
                'queued_at' => now(), 'last_error' => RagDispatchIntent::pending(), 'created_at' => now(), 'updated_at' => now()]);
            $event = RagGlobalIndexEvent::query()->where($identity)->lockForUpdate()->firstOrFail();
            if ($inserted === 0) {
                $event->fill(['revision' => $event->revision + 1, 'after_organization_id' => 0,
                    'status' => RagGlobalIndexEvent::STATUS_QUEUED, 'queued_at' => now(), 'heartbeat_at' => null,
                    'lease_expires_at' => null, 'lease_token' => null, 'completed_at' => null, 'last_error' => RagDispatchIntent::pending()])->save();
            }
            $this->dispatchAfterCommit($event);
            return $event;
        });
    }

    public function claim(int $eventId, ?int $revision): ?RagGlobalIndexEvent
    {
        $event = RagGlobalIndexEvent::query()->find($eventId);
        if (! $event || ($revision !== null && $event->revision !== $revision)) {
            return null;
        }
        $this->validate($event->source_type, $event->entity_type, $event->entity_id);
        $claimed = RagGlobalIndexEvent::query()->whereKey($eventId)->where('revision', $event->revision)
            ->where('status', RagGlobalIndexEvent::STATUS_QUEUED)->update(['status' => RagGlobalIndexEvent::STATUS_RUNNING,
                'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes($this->leaseMinutes()),
                'lease_token' => (string) Str::uuid(), 'last_error' => null, 'updated_at' => now()]);
        return $claimed === 1 ? $event->refresh() : null;
    }

    public function processBatch(RagGlobalIndexEvent $event, RagIndexingCoordinator $coordinator): void
    {
        $existing = RagSource::query()->where('source_type', $event->source_type)->where('entity_type', $event->entity_type)
            ->where('entity_id', $event->entity_id)->select('organization_id');
        $organizations = Organization::query()->where('id', '>', $event->after_organization_id)
            ->where(static fn (Builder $query): Builder => $query->where('is_active', true)->orWhereIn('id', $existing))
            ->orderBy('id')->limit(50)->get(['id']);
        foreach ($organizations as $organization) {
            if (! $this->heartbeat($event)) {
                return;
            }
            $coordinator->queueEntity((int) $organization->id, null, $event->source_type, $event->entity_type, $event->entity_id);
            if (! $this->advance($event, (int) $organization->id)) {
                return;
            }
        }
        $continuation = $organizations->count() === 50;
        $updated = $this->owned($event)->update(['status' => $continuation ? RagGlobalIndexEvent::STATUS_QUEUED : RagGlobalIndexEvent::STATUS_SUCCEEDED,
            'queued_at' => now(), 'lease_expires_at' => null, 'lease_token' => null,
            'completed_at' => $continuation ? null : now(), 'last_error' => $continuation ? RagDispatchIntent::pending() : null, 'updated_at' => now()]);
        if ($updated === 1 && $continuation) {
            $this->dispatchAfterCommit($event->refresh());
        }
    }

    public function release(RagGlobalIndexEvent $event, Throwable $exception): void
    {
        RagGlobalIndexEvent::query()->whereKey($event->id)->where('revision', $event->revision)->where('lease_token', $event->lease_token)
            ->where('status', RagGlobalIndexEvent::STATUS_RUNNING)->update(['status' => RagGlobalIndexEvent::STATUS_QUEUED,
                'queued_at' => now(), 'lease_expires_at' => null, 'lease_token' => null, 'last_error' => $exception::class, 'updated_at' => now()]);
    }

    public function recoverPending(): int
    {
        $cutoff = now();
        $recoverQueued = RagQueueBacklog::isQueueEmpty((string) config('ai-assistant.rag.live_queue', 'ai-rag-live'));
        $events = RagGlobalIndexEvent::query()->where(function (Builder $query) use ($cutoff, $recoverQueued): void {
            $this->recoveryScope($query, $cutoff, $recoverQueued);
        })->orderBy('id')->limit(25)->get();
        $recovered = 0;
        foreach ($events as $event) {
            $updated = RagGlobalIndexEvent::query()->whereKey($event->id)->where('revision', $event->revision)
                ->where('status', $event->status)->where('last_error', $event->last_error)->where('lease_token', $event->lease_token)
                ->where(function (Builder $query) use ($cutoff, $recoverQueued): void {
                    $this->recoveryScope($query, $cutoff, $recoverQueued);
                })->update(['status' => RagGlobalIndexEvent::STATUS_QUEUED, 'queued_at' => now(),
                    'lease_expires_at' => null, 'lease_token' => null, 'last_error' => RagDispatchIntent::pending(), 'updated_at' => now()]);
            if ($updated === 1) {
                $this->dispatchAfterCommit($event->refresh());
                $recovered++;
            }
        }
        return $recovered;
    }

    private function heartbeat(RagGlobalIndexEvent $event): bool
    {
        return $this->owned($event)->update(['heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes($this->leaseMinutes()), 'updated_at' => now()]) === 1;
    }

    private function advance(RagGlobalIndexEvent $event, int $organizationId): bool
    {
        return $this->owned($event)->where('after_organization_id', '<', $organizationId)->update(['after_organization_id' => $organizationId,
            'heartbeat_at' => now(), 'lease_expires_at' => now()->addMinutes($this->leaseMinutes()), 'updated_at' => now()]) === 1;
    }

    private function owned(RagGlobalIndexEvent $event): Builder
    {
        return RagGlobalIndexEvent::query()->whereKey($event->id)->where('revision', $event->revision)->where('lease_token', $event->lease_token)
            ->where('status', RagGlobalIndexEvent::STATUS_RUNNING)->where('lease_expires_at', '>', now());
    }

    private function recoveryScope(Builder $query, Carbon $cutoff, bool $recoverQueued): void
    {
        $retryMinutes = max(1, min(4, (int) config('ai-assistant.rag.queued_retry_minutes', 2)));
        $query->where(static fn (Builder $queued): Builder => $queued->where('status', RagGlobalIndexEvent::STATUS_QUEUED)
            ->when(! $recoverQueued, static function (Builder $query): void { RagDispatchIntent::scopePending($query); })
            ->where('queued_at', '<=', $cutoff->copy()->subMinutes($retryMinutes)))
            ->orWhere(static fn (Builder $running): Builder => $running->where('status', RagGlobalIndexEvent::STATUS_RUNNING)
                ->where('lease_expires_at', '<=', $cutoff));
    }

    private function leaseMinutes(): int
    {
        return max(1, min(4, (int) config('ai-assistant.rag.global_lease_minutes', 2)));
    }

    private function validate(string $sourceType, string $entityType, string|int $entityId): void
    {
        if (! self::supports($sourceType, $entityType, $entityId) || ! $this->registry->collector($sourceType)?->enabled()) {
            throw new InvalidArgumentException('Unsupported global RAG entity');
        }
    }

    private function dispatchAfterCommit(RagGlobalIndexEvent $event): void
    {
        $eventId = $event->id;
        $revision = $event->revision;
        $marker = $event->last_error;
        DB::afterCommit(function () use ($eventId, $revision, $marker): void {
            try {
                $current = RagGlobalIndexEvent::query()->whereKey($eventId)->where('revision', $revision)
                    ->where('status', RagGlobalIndexEvent::STATUS_QUEUED)->where('last_error', $marker)->first();
                if (! $current || ! RagDispatchIntent::isPending($marker)) {
                    return;
                }
                $this->bus->dispatch(new IndexGlobalRagEntityJob($current->source_type, $current->entity_type, $current->entity_id,
                    $current->after_organization_id, $current->id, $current->revision));
                RagGlobalIndexEvent::query()->whereKey($eventId)->where('revision', $revision)
                    ->where('status', RagGlobalIndexEvent::STATUS_QUEUED)->where('last_error', $marker)
                    ->update(['last_error' => null, 'updated_at' => now()]);
            } catch (Throwable $exception) {
                try {
                    RagGlobalIndexEvent::query()->whereKey($eventId)->where('revision', $revision)->where('status', RagGlobalIndexEvent::STATUS_QUEUED)
                        ->where('last_error', $marker)->update(['last_error' => RagDispatchIntent::failed((string) $marker, $exception), 'updated_at' => now()]);
                } catch (Throwable $persistenceException) {
                    $this->warning('ai_assistant.rag.global_dispatch_status_failed', $eventId, $persistenceException);
                }
                $this->warning('ai_assistant.rag.global_dispatch_failed', $eventId, $exception);
            }
        });
    }

    private function warning(string $event, ?int $eventId, Throwable $exception): void
    {
        try {
            $this->logger->warning($event, ['event_id' => $eventId, 'exception_class' => $exception::class]);
        } catch (Throwable) {
        }
    }
}
