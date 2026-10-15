<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use Database\Seeders\ProductionLoadTestSeeder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LoadTestCredentialGuardTest extends TestCase
{
    public function test_missing_and_short_passwords_fail_before_database_or_application_access(): void
    {
        $previousConfirmation = getenv('MOST_LOAD_TEST_SEEDER_CONFIRM');
        $previousPassword = getenv('LOAD_TEST_PASSWORD');
        try {
            putenv('MOST_LOAD_TEST_SEEDER_CONFIRM=yes');
            foreach (['', 'short'] as $password) {
                putenv('LOAD_TEST_PASSWORD='.$password);
                try {
                    (new ProductionLoadTestSeeder())->run();
                    self::fail('A load-test password must be explicitly supplied.');
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('LOAD_TEST_PASSWORD must contain at least 16 characters', $exception->getMessage());
                }
            }
        } finally {
            putenv($previousConfirmation === false ? 'MOST_LOAD_TEST_SEEDER_CONFIRM' : 'MOST_LOAD_TEST_SEEDER_CONFIRM='.$previousConfirmation);
            putenv($previousPassword === false ? 'LOAD_TEST_PASSWORD' : 'LOAD_TEST_PASSWORD='.$previousPassword);
        }
    }
}
