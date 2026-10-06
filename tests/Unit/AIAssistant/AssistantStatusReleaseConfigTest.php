<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantStatusReleaseConfigTest extends TestCase
{
    #[DataProvider('environmentSources')]
    public function test_cached_configuration_keeps_release_when_runtime_environment_is_cleared(string $source): void
    {
        $key = 'MOST_RELEASE_SHA';
        $originalEnv = $_ENV[$key] ?? null;
        $originalServer = $_SERVER[$key] ?? null;
        $originalProcess = getenv($key);
        $release = str_repeat('a', 40);
        try {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($source === 'env') {
                $_ENV[$key] = $release;
            } elseif ($source === 'server') {
                $_SERVER[$key] = $release;
            } else {
                putenv($key.'='.$release);
            }
            $configuration = require dirname(__DIR__, 3).'/app/BusinessModules/Features/AIAssistant/config/ai-assistant.php';
            $cached = unserialize(serialize($configuration));
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            self::assertFalse(getenv($key));
            self::assertSame($release, $cached['status_snapshot_release'] ?? null);
        } finally {
            unset($_ENV[$key], $_SERVER[$key]);
            if ($originalEnv !== null) { $_ENV[$key] = $originalEnv; }
            if ($originalServer !== null) { $_SERVER[$key] = $originalServer; }
            putenv($originalProcess === false ? $key : $key.'='.$originalProcess);
        }
    }

    public static function environmentSources(): array
    {
        return [['env'], ['server'], ['process']];
    }
}
