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
}
