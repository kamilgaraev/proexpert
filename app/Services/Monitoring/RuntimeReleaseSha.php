<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

final class RuntimeReleaseSha
{
    public static function current(): ?string
    {
        try {
            $release = (app()->bound('config') ? config('ai-assistant.status_snapshot_release') : null) ?: getenv('MOST_RELEASE_SHA');
        } catch (\Throwable) {
            return null;
        }

        return is_string($release) && preg_match('/^[a-f0-9]{40}$/D', $release) === 1 ? $release : null;
    }
}
