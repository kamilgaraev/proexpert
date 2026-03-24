<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class AdminControllersResponseContractTest extends TestCase
{
    public function test_admin_controllers_do_not_use_raw_json_responses(): void
    {
        $directory = dirname(__DIR__, 3) . '/app/Http/Controllers/Api/V1/Admin';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        $violations = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname()) ?: '';
            if (preg_match('/response\s*\(\s*\)\s*->\s*json\s*\(/', $contents) === 1) {
                $violations[] = str_replace(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR, '', $file->getPathname());
            }
        }

        $this->assertSame([], $violations, 'Raw response()->json() found in admin controllers: ' . implode(', ', $violations));
    }
}
