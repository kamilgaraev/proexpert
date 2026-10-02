<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Jobs\PrepareAssistantNativeAttachmentsJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class AssistantNativeAttachmentPreparationQueue
{
    private const SUPPORTED_RUN_TYPES = ['legal_business', 'operations_quality'];

    private const PREPARABLE_RUN_STATUSES = [
        RagIndexRun::STATUS_QUEUED,
        RagIndexRun::STATUS_RUNNING,
        RagIndexRun::STATUS_SUCCEEDED,
        RagIndexRun::STATUS_FAILED,
    ];

    private const DISPATCH_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:dispatch:';

    private const COMPLETE_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:complete:';

    private const ATTEMPTS_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:attempts:';

    private const EXHAUSTED_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:exhausted:';

    private const RECOVERY_CURSOR_KEY = 'ai-assistant:native-attachment-preparation:recovery-cursor';

    private const RECOVERY_HIGH_WATER_KEY = 'ai-assistant:native-attachment-preparation:recovery-high-water';

    private const RUN_LOCK_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:run-lock:';

    private const PAGE_LOCK_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:page-lock:';

    private const PAGE_PROGRESS_KEY_PREFIX = 'ai-assistant:native-attachment-preparation:page-progress:';

    private const RECOVERY_PAGE_SIZE = 100;

    private const RECOVERY_WINDOW_DAYS = 7;

    private const RECOVERY_STATE_TTL_DAYS = 30;

    private const MAX_DISPATCH_CYCLES = 3;

    private const DISPATCH_TTL_MINUTES = 10;

    private const COMPLETE_TTL_DAYS = 30;

    private const PAGE_LOCK_TTL_SECONDS = 100;

    private const RUN_LOCK_TTL_SECONDS = 10;

    public function dispatchPendingNativeRuns(int $limit = 10): int
    {
        $limit = max(1, min(10, $limit));
        $cursor = max(0, (int) Cache::get(self::RECOVERY_CURSOR_KEY, 0));
        $highWater = Cache::get(self::RECOVERY_HIGH_WATER_KEY);
        if (! is_numeric($highWater)) {
            $highWater = (int) (RagIndexRun::query()->max('id') ?? 0);
            Cache::put(self::RECOVERY_HIGH_WATER_KEY, $highWater, now()->addDays(self::RECOVERY_STATE_TTL_DAYS));
            $cursor = 0;
        } else {
            $highWater = (int) $highWater;
        }

        if ($highWater < 1) {
            Cache::forget(self::RECOVERY_CURSOR_KEY);
            Cache::forget(self::RECOVERY_HIGH_WATER_KEY);

            return 0;
        }

        if ($cursor >= $highWater) {
            $highWater = (int) (RagIndexRun::query()->max('id') ?? 0);
            $cursor = 0;
            if ($highWater < 1) {
                Cache::forget(self::RECOVERY_CURSOR_KEY);
                Cache::forget(self::RECOVERY_HIGH_WATER_KEY);

                return 0;
            }
            Cache::put(self::RECOVERY_HIGH_WATER_KEY, $highWater, now()->addDays(self::RECOVERY_STATE_TTL_DAYS));
        }

        $runs = $this->pendingRunsAfter($cursor, $highWater);
        if ($runs->isEmpty()) {
            Cache::put(self::RECOVERY_CURSOR_KEY, $highWater, now()->addDays(self::RECOVERY_STATE_TTL_DAYS));

            return 0;
        }

        $lastSeenId = $cursor;
        $dispatched = 0;
        foreach ($runs as $run) {
            $lastSeenId = (int) $run->id;
            if ($this->dispatchActiveRun($run)) {
                $dispatched++;
                if ($dispatched >= $limit) {
                    break;
                }
            }
        }

        Cache::put(self::RECOVERY_CURSOR_KEY, $lastSeenId, now()->addDays(self::RECOVERY_STATE_TTL_DAYS));

        return $dispatched;
    }

    private function pendingRunsAfter(int $cursor, int $highWater): \Illuminate\Database\Eloquent\Collection
    {
        $cutoff = now()->subDays(self::RECOVERY_WINDOW_DAYS);

        return RagIndexRun::query()
            ->where(static function (Builder $status) use ($cutoff): void {
                $status->where('status', RagIndexRun::STATUS_QUEUED)
                    ->orWhere(static function (Builder $running) use ($cutoff): void {
                        $running->where('status', RagIndexRun::STATUS_RUNNING)
                            ->where(static function (Builder $activity) use ($cutoff): void {
                                $activity->where('heartbeat_at', '>=', $cutoff)->orWhere('started_at', '>=', $cutoff);
                            });
                    })
                    ->orWhere(static fn (Builder $finished): Builder => $finished
                        ->whereIn('status', [RagIndexRun::STATUS_SUCCEEDED, RagIndexRun::STATUS_FAILED])
                        ->where('finished_at', '>=', $cutoff));
            })
            ->whereNull('project_id')
            ->whereNull('entity_type')
            ->whereIn('source_type', self::SUPPORTED_RUN_TYPES)
            ->where('id', '>', $cursor)
            ->where('id', '<=', $highWater)
            ->orderBy('id')
            ->limit(self::RECOVERY_PAGE_SIZE)
            ->get();
    }

    public function withPageLock(int $runId, string $sourceType, int $nativeTypeIndex, ?string $afterSourceId, \Closure $callback): bool
    {
        $lock = Cache::lock($this->pageLockKey($runId, $sourceType, $nativeTypeIndex, $afterSourceId), self::PAGE_LOCK_TTL_SECONDS);

        return $lock->get(static function () use ($callback): bool {
            $callback();

            return true;
        }) === true;
    }

    public function pageProgress(int $runId, string $sourceType, int $nativeTypeIndex, ?string $afterSourceId): ?array
    {
        $progress = Cache::get($this->pageProgressKey($runId, $sourceType, $nativeTypeIndex, $afterSourceId));

        return is_array($progress) ? $progress : null;
    }

    public function checkpointPageContinuation(
        int $runId,
        string $sourceType,
        int $nativeTypeIndex,
        ?string $afterSourceId,
        int $nextNativeTypeIndex,
        ?string $nextAfterSourceId,
        string $token,
        \Closure $dispatch,
    ): void {
        $key = $this->pageProgressKey($runId, $sourceType, $nativeTypeIndex, $afterSourceId);
        $progress = Cache::get($key);
        $next = [
            'state' => 'pending',
            'next_native_type_index' => $nextNativeTypeIndex,
            'next_after_source_id' => $nextAfterSourceId,
            'dispatched_token' => null,
        ];
        if (! is_array($progress) || ($progress['state'] ?? null) !== 'pending') {
            Cache::put($key, $next, now()->addDays(self::COMPLETE_TTL_DAYS));
        }

        $this->dispatchPendingContinuation($key, $token, $dispatch);
    }

    public function resumePageContinuation(
        int $runId,
        string $sourceType,
        int $nativeTypeIndex,
        ?string $afterSourceId,
        string $token,
        \Closure $dispatch,
    ): bool {
        $key = $this->pageProgressKey($runId, $sourceType, $nativeTypeIndex, $afterSourceId);
        $progress = Cache::get($key);
        if (! is_array($progress) || ($progress['state'] ?? null) === 'complete') {
            return false;
        }

        if ((int) ($progress['next_native_type_index'] ?? -1) < 0) {
            return false;
        }

        if (($progress['state'] ?? null) === 'dispatched' && ($progress['dispatched_token'] ?? null) === $token) {
            return true;
        }

        $this->dispatchPendingContinuation($key, $token, $dispatch);

        return true;
    }

    public function markPageComplete(int $runId, string $sourceType, int $nativeTypeIndex, ?string $afterSourceId): void
    {
        Cache::put($this->pageProgressKey($runId, $sourceType, $nativeTypeIndex, $afterSourceId), [
            'state' => 'complete',
            'next_native_type_index' => null,
            'next_after_source_id' => null,
            'dispatched_token' => null,
        ], now()->addDays(self::COMPLETE_TTL_DAYS));
    }

    private function dispatchPendingContinuation(string $progressKey, string $token, \Closure $dispatch): void
    {
        $progress = Cache::get($progressKey);
        if (! is_array($progress) || ($progress['state'] ?? null) === 'complete') {
            return;
        }
        if (($progress['state'] ?? null) === 'dispatched' && ($progress['dispatched_token'] ?? null) === $token) {
            return;
        }

        $dispatch((int) $progress['next_native_type_index'], $progress['next_after_source_id'] ?? null);
        $progress['state'] = 'dispatched';
        $progress['dispatched_token'] = $token;
        Cache::put($progressKey, $progress, now()->addDays(self::COMPLETE_TTL_DAYS));
    }

    private function pageLockKey(int $runId, string $sourceType, int $nativeTypeIndex, ?string $afterSourceId): string
    {
        return self::PAGE_LOCK_KEY_PREFIX.$this->pageIdentity($runId, $sourceType, $nativeTypeIndex, $afterSourceId);
    }

    private function pageProgressKey(int $runId, string $sourceType, int $nativeTypeIndex, ?string $afterSourceId): string
    {
        return self::PAGE_PROGRESS_KEY_PREFIX.$this->pageIdentity($runId, $sourceType, $nativeTypeIndex, $afterSourceId);
    }

    private function pageIdentity(int $runId, string $sourceType, int $nativeTypeIndex, ?string $afterSourceId): string
    {
        return $runId.':'.$sourceType.':'.$nativeTypeIndex.':'.($afterSourceId ?? 'first');
    }

    public function dispatchQueuedRun(RagIndexRun $run): bool
    {
        if ($run->status !== RagIndexRun::STATUS_QUEUED) {
            return false;
        }

        return $this->dispatchActiveRun($run);
    }

    public function dispatchRunningRun(RagIndexRun $run): bool
    {
        if ($run->status !== RagIndexRun::STATUS_RUNNING) {
            return false;
        }

        return $this->dispatchActiveRun($run);
    }

    public function dispatchRetryRun(int $runId, int $organizationId, string $sourceType): bool
    {
        if (! in_array($sourceType, self::SUPPORTED_RUN_TYPES, true)) {
            return false;
        }

        $run = RagIndexRun::query()->whereKey($runId)
            ->where('organization_id', $organizationId)
            ->where('source_type', $sourceType)
            ->whereIn('status', self::PREPARABLE_RUN_STATUSES)
            ->whereNull('project_id')
            ->whereNull('entity_type')
            ->first();
        if (! $run instanceof RagIndexRun) {
            return false;
        }

        return $this->dispatchActiveRun($run);
    }

    public function shouldPrepareInline(?int $runId): bool
    {
        if ($runId === null) {
            return true;
        }

        try {
            return ! $this->isDispatchedOrComplete($runId);
        } catch (Throwable $exception) {
            Log::warning('ai_assistant.native_attachment_preparation_marker_unavailable', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
            ]);

            return true;
        }
    }

    private function dispatchActiveRun(RagIndexRun $run): bool
    {
        if (
            ! in_array($run->status, self::PREPARABLE_RUN_STATUSES, true)
            || $run->project_id !== null
            || $run->entity_type !== null
            || ! in_array($run->source_type, self::SUPPORTED_RUN_TYPES, true)
        ) {
            return false;
        }

        $runId = (int) $run->id;
        $token = (string) Str::uuid();
        $dispatchKey = $this->dispatchKey($runId);
        $dispatchData = null;
        try {
            $dispatchData = $this->withRunLock($runId, function () use ($run, $runId, $dispatchKey, $token): ?array {
                if ($this->isComplete($runId)) {
                    return null;
                }
                $attemptsKey = $this->attemptsKey($runId);
                if ((int) Cache::get($attemptsKey, 0) >= self::MAX_DISPATCH_CYCLES) {
                    $this->logExhaustedOnce($runId, (int) $run->organization_id, (string) $run->source_type);

                    return null;
                }
                if (! Cache::add($dispatchKey, $token, now()->addMinutes(self::DISPATCH_TTL_MINUTES))) {
                    return null;
                }
                $cycle = (int) Cache::get($attemptsKey, 0) + 1;
                Cache::put($attemptsKey, $cycle, now()->addDays(self::COMPLETE_TTL_DAYS));

                return ['token' => $token, 'cycle' => $cycle];
            });
        } catch (Throwable $exception) {
            Log::warning('ai_assistant.native_attachment_preparation_dispatch_failed', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
        if (! is_array($dispatchData)) {
            return false;
        }
        $token = $dispatchData['token'];
        $cycle = $dispatchData['cycle'];

        try {
            $pending = PrepareAssistantNativeAttachmentsJob::dispatch(
                $runId,
                (int) $run->organization_id,
                (string) $run->source_type,
                $token,
                $cycle,
            )->onQueue('default');
            if ($cycle > 1) {
                $pending->delay(now()->addMinute());
            }

            return true;
        } catch (Throwable $exception) {
            try {
                $this->releaseDispatch($runId, $token);
            } catch (Throwable $cleanupException) {
                Log::warning('ai_assistant.native_attachment_preparation_marker_cleanup_failed', [
                    'run_id' => $runId,
                    'exception_class' => $cleanupException::class,
                ]);
            }
            Log::warning('ai_assistant.native_attachment_preparation_dispatch_failed', [
                'run_id' => $runId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    public function isDispatchedOrComplete(int $runId): bool
    {
        return Cache::has($this->dispatchKey($runId)) || $this->isComplete($runId);
    }

    public function isComplete(int $runId): bool
    {
        return Cache::get($this->completeKey($runId)) === true;
    }

    public function hasDispatchToken(int $runId, string $token): bool
    {
        return Cache::get($this->dispatchKey($runId)) === $token;
    }

    public function renewDispatch(int $runId, string $token): bool
    {
        return $this->withRunLock($runId, function () use ($runId, $token): bool {
            if ($this->isComplete($runId)) {
                return false;
            }

            $dispatchKey = $this->dispatchKey($runId);
            $currentToken = Cache::get($dispatchKey);
            if ($currentToken !== null && $currentToken !== $token) {
                return false;
            }

            Cache::put($dispatchKey, $token, now()->addMinutes(self::DISPATCH_TTL_MINUTES));

            return true;
        });
    }

    public function markComplete(int $runId, string $token): void
    {
        $this->withRunLock($runId, function () use ($runId, $token): void {
            if (! $this->hasDispatchToken($runId, $token)) {
                return;
            }

            Cache::put($this->completeKey($runId), true, now()->addDays(self::COMPLETE_TTL_DAYS));
            Cache::forget($this->dispatchKey($runId));
        });
    }

    public function releaseDispatch(int $runId, string $token): void
    {
        $this->withRunLock($runId, function () use ($runId, $token): void {
            if ($this->hasDispatchToken($runId, $token)) {
                Cache::forget($this->dispatchKey($runId));
            }
        });
    }

    private function withRunLock(int $runId, \Closure $callback): mixed
    {
        return Cache::lock(self::RUN_LOCK_KEY_PREFIX.$runId, self::RUN_LOCK_TTL_SECONDS)->block(1, $callback);
    }

    private function dispatchKey(int $runId): string
    {
        return self::DISPATCH_KEY_PREFIX.$runId;
    }

    private function completeKey(int $runId): string
    {
        return self::COMPLETE_KEY_PREFIX.$runId;
    }

    private function attemptsKey(int $runId): string
    {
        return self::ATTEMPTS_KEY_PREFIX.$runId;
    }

    private function logExhaustedOnce(int $runId, int $organizationId, string $sourceType): void
    {
        if (! Cache::add(self::EXHAUSTED_KEY_PREFIX.$runId, true, now()->addDays(self::COMPLETE_TTL_DAYS))) {
            return;
        }

        Log::error('ai_assistant.native_attachment_preparation_cycles_exhausted', [
            'run_id' => $runId,
            'organization_id' => $organizationId,
            'source_type' => $sourceType,
            'dispatch_cycles' => self::MAX_DISPATCH_CYCLES,
        ]);
    }
}
