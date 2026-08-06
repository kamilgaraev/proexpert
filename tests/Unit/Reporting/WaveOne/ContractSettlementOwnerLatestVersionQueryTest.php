<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerSource;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ContractSettlementOwnerLatestVersionQueryTest extends TestCase
{
    public function test_owner_source_selects_only_latest_as_of_version_in_postgres(): void
    {
        $sourceFile = (new ReflectionClass(ContractSettlementOwnerSource::class))->getFileName();
        self::assertIsString($sourceFile);
        $source = file_get_contents($sourceFile);

        self::assertIsString($source);
        self::assertStringContainsString(
            "selectRaw('DISTINCT ON (owner_type, owner_id) id')",
            $source,
        );
        self::assertStringContainsString("->whereIn('id', \$latestIds)", $source);
        self::assertStringContainsString("->orderByDesc('version')", $source);
        self::assertStringNotContainsString(
            '->unique(static fn (ContractSettlementOwnerVersion',
            $source,
        );

        $migration = file_get_contents(dirname(__DIR__, 4)
            .'/app/BusinessModules/Features/ContractManagement/migrations/'
            .'2026_08_06_000110_add_latest_owner_version_index.php');
        self::assertIsString($migration);
        self::assertStringContainsString('public $withinTransaction = false', $migration);
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY IF NOT EXISTS', $migration);
        self::assertStringContainsString('index_state.indisvalid = false', $migration);
        self::assertStringContainsString('DROP INDEX CONCURRENTLY IF EXISTS', $migration);
        self::assertStringContainsString(
            '(organization_id, owner_type, owner_id, version DESC, occurred_at)',
            $migration,
        );
        self::assertStringContainsString('INCLUDE (id)', $migration);
    }
}
