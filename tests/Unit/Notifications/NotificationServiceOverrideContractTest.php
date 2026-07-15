<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

final class NotificationServiceOverrideContractTest extends TestCase
{
    public function test_send_overrides_preserve_the_trailing_interfaces_parameter(): void
    {
        $root = dirname(__DIR__, 3);
        $incompatibleOverrides = [];

        foreach (['app', 'tests'] as $directory) {
            $files = new RegexIterator(
                new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)),
                '/\.php$/i',
            );

            foreach ($files as $file) {
                $source = file_get_contents($file->getPathname());

                if (! is_string($source) || ! preg_match('/extends\s+NotificationService\b/', $source)) {
                    continue;
                }

                preg_match_all(
                    '/public\s+function\s+send\s*\((.*?)\)\s*(?::[^\{]+)?\{/s',
                    $source,
                    $overrides,
                );

                foreach ($overrides[1] as $parameters) {
                    if (! preg_match(
                        '/string\|array\|null\s+\$interfaces\s*=\s*null\s*,?\s*$/',
                        $parameters,
                    )) {
                        $incompatibleOverrides[] = $file->getPathname();
                    }
                }
            }
        }

        self::assertSame([], $incompatibleOverrides);
    }
}
