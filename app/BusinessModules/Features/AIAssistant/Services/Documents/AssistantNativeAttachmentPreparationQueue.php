<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Jobs\PrepareAssistantNativeAttachmentsJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
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
    private const RECOVERY_PAGE_SIZE = 100;
    private const MAX_DISPATCH_CYCLES = 3;
    private const DISPATCH_TTL_MINUTES = 10;
    private const COMPLETE_TTL_DAYS = 7;

    public function dispatchPendingQueuedRuns(int $limit = 10): int
    {
        $limit = max(1, min(10, $limit));
        $cursor = max(0, (int) Cache::get(self::RECOVERY_CURSOR_KEY, 0));
        $runs = $this->pendingRunsAfter($cursor);
        if ($runs->isEmpty() && $cursor > 0) {
            $cursor = 0;
            $runs = $this->pendingRunsAfter($cursor);
        }
        if ($runs->isEmpty()) {
            Cache::forget(self::RECOVERY_CURSOR_KEY);

            return 0;
        }

        $lastSeenId = $cursor;
        $dispatched = 0;
        foreach ($runs as $run) {
            $lastSeenId = (int) $run->id;
            if ($this->dispatchQueuedRun($run)) {
                $dispatched++;
                if ($dispatched >= $limit) {
                    break;
                }
            }
        }

        Cache::put(self::RECOVERY_CURSOR_KEY, $lastSeenId, now()->addDay());

        return $dispatched;
    }

    private function pendingRunsAfter(int $cursor): \Illuminate\Database\Eloquent\Collection
    {
        return RagIndexRun::query()
            ->where('status', RagIndexRun::STATUS_QUEUED)
            ->whereNull('project_id')
            ->whereNull('entity_type')
            ->whereIn('source_type', self::SUPPORTED_RUN_TYPES)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit(self::RECOVERY_PAGE_SIZE)
            ->get();
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
        try {
            if ($this->isComplete($runId)) {
                return false;
            }
            $attemptsKey = $this->attemptsKey($runId);
            if ((int) Cache::get($attemptsKey, 0) >= self::MAX_DISPATCH_CYCLES) {
                $this->logExhaustedOnce($runId, (int) $run->organization_id, (string) $run->source_type);

                return false;
            }
            if (! Cache::add($dispatchKey, $token, now()->addMinutes(self::DISPATCH_TTL_MINUTES))) {
                return false;
            }
            $cycle = (int) Cache::get($attemptsKey, 0) + 1;
            Cache::put($attemptsKey, $cycle, now()->addDays(self::COMPLETE_TTL_DAYS));
        } catch (Throwable $exception) {
            try {
                Cache::forget($dispatchKey);
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
                Cache::forget($dispatchKey);
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
        if (! $this->hasDispatchToken($runId, $token)) {
            return false;
        }

        Cache::put($this->dispatchKey($runId), $token, now()->addMinutes(self::DISPATCH_TTL_MINUTES));

        return true;
    }

    public function markComplete(int $runId, string $token): void
    {
        if (! $this->hasDispatchToken($runId, $token)) {
            return;
        }

        Cache::put($this->completeKey($runId), true, now()->addDays(self::COMPLETE_TTL_DAYS));
        $this->releaseDispatch($runId, $token);
    }

    public function releaseDispatch(int $runId, string $token): void
    {
        if ($this->hasDispatchToken($runId, $token)) {
            Cache::forget($this->dispatchKey($runId));
        }
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
