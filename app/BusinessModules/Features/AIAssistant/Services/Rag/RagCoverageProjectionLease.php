<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotDiagnostics;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RagCoverageProjectionLease
{
    public static function acquire(Lock $lease, string $name, int $revision, int $seconds): bool
    {
        if (! $lease->get()) {
            try {
                $ownership = Cache::get($name.':revision-owner');
                if (! is_array($ownership) || ! is_int($ownership['revision'] ?? null)
                    || $ownership['revision'] < 0 || $ownership['revision'] >= $revision
                    || ! is_string($ownership['owner'] ?? null) || $ownership['owner'] === '' || strlen($ownership['owner']) > 200) {
                    return false;
                }
                if (! Cache::restoreLock($name, $ownership['owner'])->release() || ! $lease->get()) {
                    return false;
                }
            } catch (Throwable $exception) {
                AssistantStatusSnapshotDiagnostics::coverageRefresh('lease_metadata_failed', true, exception: $exception);

                return false;
            }
        }
        try {
            Cache::put($name.':revision-owner', ['owner' => $lease->owner(), 'revision' => $revision], $seconds);
        } catch (Throwable $exception) {
            AssistantStatusSnapshotDiagnostics::coverageRefresh('lease_metadata_failed', true, exception: $exception);
        }

        return true;
    }
}
