<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class BudgetingPortfolioProviderTest extends TestCase
{
    #[Test]
    public function project_portfolio_health_provider_contract(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        self::assertTrue(Schema::hasColumns('budgeting_portfolio_report_snapshots', [
            'organization_id',
            'report_code',
            'source_hash',
            'query_hash',
            'formula_version',
            'watermarks',
            'source_refs',
        ]));
        self::assertTrue(Schema::hasColumns('budgeting_project_portfolio_health_rows', [
            'organization_id',
            'snapshot_id',
            'project_id',
            'currency',
            'margin',
            'margin_percent',
            'row_key',
            'source_refs',
        ]));
    }

    #[Test]
    public function portfolio_liquidity_provider_contract(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        self::assertTrue(Schema::hasColumns('budgeting_portfolio_liquidity_rows', [
            'organization_id',
            'snapshot_id',
            'forecast_date',
            'project_id',
            'currency',
            'scenario',
            'opening',
            'inflow',
            'outflow',
            'closing',
            'gap',
            'row_key',
            'source_refs',
        ]));
    }
}
