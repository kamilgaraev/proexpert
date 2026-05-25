<?php

declare(strict_types=1);

namespace Tests\Unit\SiteRequests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class SiteRequestTranslationKeysTest extends TestCase
{
    public function test_all_site_request_translation_keys_used_by_module_exist(): void
    {
        $messages = require dirname(__DIR__, 3) . '/lang/ru/site_requests.php';
        $usedKeys = $this->collectSiteRequestTranslationKeys();

        self::assertNotEmpty($usedKeys);

        foreach ($usedKeys as $key) {
            self::assertTrue(
                $this->hasNestedKey($messages, $key),
                sprintf('Missing lang/ru/site_requests.php key: %s', $key)
            );
        }
    }

    /**
     * @return string[]
     */
    private function collectSiteRequestTranslationKeys(): array
    {
        $root = dirname(__DIR__, 3) . '/app/BusinessModules/Features/SiteRequests';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $keys = [];

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            preg_match_all("/trans_message\\('site_requests\\.([^']+)'/", $contents, $matches);

            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }

        $keys = array_keys($keys);
        sort($keys);

        return $keys;
    }

    /**
     * @param array<string, mixed> $messages
     */
    private function hasNestedKey(array $messages, string $key): bool
    {
        $current = $messages;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return false;
            }

            $current = $current[$segment];
        }

        return true;
    }
}
