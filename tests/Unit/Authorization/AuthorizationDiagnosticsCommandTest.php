<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;

final class AuthorizationDiagnosticsCommandTest extends TestCase
{
    public function test_role_cache_clear_does_not_flush_unrelated_cache_entries(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/Console/Commands/AuthorizationDiagnosticsCommand.php');

        self::assertIsString($source);
        self::assertStringContainsString('$this->roleScanner->clearCache();', $source);
        self::assertStringNotContainsString('Cache::flush()', $source);
    }

    public function test_production_deploy_refreshes_role_cache_after_migrations(): void
    {
        $workflow = file_get_contents(dirname(__DIR__, 3).'/.github/workflows/deploy-backend.yml');

        self::assertIsString($workflow);
        self::assertMatchesRegularExpression(
            '/php artisan migrate:safe --force\R\s+MOST_IMAGE_REF="\$\{IMAGE_REF\}" docker compose run --rm --no-deps api php artisan auth:diagnose --clear-cache/',
            $workflow,
        );
    }
}
