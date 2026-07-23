<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class LegalDocumentScannerConfigurationTest extends TestCase
{
    public function test_it_uses_the_bundled_clamav_service_by_default(): void
    {
        $key = 'LEGAL_ARCHIVE_SCANNER';
        $previous = [
            'environment' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'process' => getenv($key),
        ];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);

        try {
            $config = require dirname(__DIR__, 3).'/config/file-uploads.php';
        } finally {
            $this->restoreEnvironmentVariable($key, $previous);
        }

        self::assertSame('clamav', $config['legal_archive']['scanner']);
        self::assertSame('clamav', $config['legal_archive']['clamav']['host']);
        self::assertSame(3310, $config['legal_archive']['clamav']['port']);
    }

    /** @param array{environment: mixed, server: mixed, process: string|false} $previous */
    private function restoreEnvironmentVariable(string $key, array $previous): void
    {
        if ($previous['environment'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $previous['environment'];
        }
        if ($previous['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $previous['server'];
        }
        if ($previous['process'] === false) {
            putenv($key);
        } else {
            putenv($key.'='.$previous['process']);
        }
    }
}
