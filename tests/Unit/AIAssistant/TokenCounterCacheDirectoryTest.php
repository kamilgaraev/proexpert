<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\Support\AI\TokenCounter;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class TokenCounterCacheDirectoryTest extends TestCase
{
    public function test_cache_directory_can_be_created_and_reused(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'token-counter-'.bin2hex(random_bytes(8));
        $ensureDirectory = new ReflectionMethod(TokenCounter::class, 'ensureCacheDirectory');

        try {
            $ensureDirectory->invoke(null, $directory);
            self::assertDirectoryExists($directory);
            $ensureDirectory->invoke(null, $directory);
            self::assertDirectoryExists($directory);
        } finally {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_cache_lock_serializes_access_and_is_released_after_failure(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'token-counter-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $lockFile = $directory.DIRECTORY_SEPARATOR.'.o200k_base.lock';
        $withCacheLock = new ReflectionMethod(TokenCounter::class, 'withCacheLock');

        try {
            $withCacheLock->invoke(null, $directory, function () use ($lockFile): void {
                $otherProcessLock = fopen($lockFile, 'c');
                self::assertIsResource($otherProcessLock);

                try {
                    self::assertFalse(flock($otherProcessLock, LOCK_EX | LOCK_NB));
                } finally {
                    fclose($otherProcessLock);
                }

                throw new \RuntimeException('Interrupted load');
            });
            self::fail('Expected interrupted load');
        } catch (\RuntimeException $exception) {
            self::assertSame('Interrupted load', $exception->getMessage());
        } finally {
            $afterFailure = fopen($lockFile, 'c');
            self::assertIsResource($afterFailure);
            self::assertTrue(flock($afterFailure, LOCK_EX | LOCK_NB));
            flock($afterFailure, LOCK_UN);
            fclose($afterFailure);
            unlink($lockFile);
            rmdir($directory);
        }
    }
}
