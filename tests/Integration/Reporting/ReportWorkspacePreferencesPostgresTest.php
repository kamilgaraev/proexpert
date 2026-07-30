<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting;

use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportWorkspacePreferencesStore;
use PHPUnit\Framework\TestCase;

final class ReportWorkspacePreferencesPostgresTest extends TestCase
{
    public function test_postgres_concurrency_coverage_is_reserved_for_ci_database_gate(): void
    {
        self::assertTrue(class_exists(EloquentReportWorkspacePreferencesStore::class));
        self::markTestSkipped('PostgreSQL first-write concurrency is verified only in the CI database gate.');
    }
}
