<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\WaveOne;

use App\BusinessModules\Core\Payments\DTOs\PaymentCalendarSourceFilters;
use App\BusinessModules\Core\Payments\Services\PaymentCalendarSourceService;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityAsOfSource;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[Group('postgres')]
final class BudgetingPortfolioProviderTest extends TestCase
{
    #[Test]
    public function liquidity_source_version_is_append_only_in_postgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('budgeting_portfolio_liquidity_source_versions')->insertGetId([
            'organization_id' => 720001,
            'source_type' => 'opening_balance',
            'source_id' => 'immutable-1',
            'source_version' => hash('sha256', 'immutable-liquidity'),
            'occurred_at' => '2026-07-30 10:00:00+00',
            'created_at' => '2026-07-30 10:00:00+00',
            'recorded_at' => '2026-07-30 10:00:00+00',
            'effective_at' => '2026-07-30 00:00:00+00',
            'payload' => null,
            'source_hash' => hash('sha256', 'immutable-liquidity'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('budgeting_portfolio_liquidity_source_versions')
            ->where('id', $id)
            ->update(['source_hash' => hash('sha256', 'tampered')]);
    }

    #[Test]
    public function liquidity_as_of_excludes_a_version_recorded_after_the_cutoff(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $organizationId = 730001;
        $base = [
            'organization_id' => $organizationId,
            'source_type' => 'opening_balance',
            'source_id' => 'balance-1',
            'occurred_at' => '2026-07-30 10:00:00+00',
            'created_at' => '2026-07-30 09:00:00+00',
            'effective_at' => '2026-07-30 00:00:00+00',
        ];
        DB::table('budgeting_portfolio_liquidity_source_versions')->insert([
            ...$base,
            'source_version' => hash('sha256', 'known-before-cutoff'),
            'recorded_at' => '2026-07-30 11:00:00+00',
            'payload' => json_encode([
                'kind' => 'opening_balance',
                'id' => 'balance-1',
                'organization_id' => $organizationId,
                'balance_date' => '2026-07-30',
                'currency' => 'RUB',
                'amount' => '100.00',
                'status' => 'approved',
            ], JSON_THROW_ON_ERROR),
            'source_hash' => hash('sha256', 'known-before-cutoff'),
        ]);
        DB::table('budgeting_portfolio_liquidity_source_versions')->insert([
            ...$base,
            'source_version' => hash('sha256', 'recorded-after-cutoff'),
            'recorded_at' => '2026-07-30 13:00:00+00',
            'payload' => json_encode([
                'kind' => 'opening_balance',
                'id' => 'balance-1',
                'organization_id' => $organizationId,
                'balance_date' => '2026-07-30',
                'currency' => 'RUB',
                'amount' => '900.00',
                'status' => 'approved',
            ], JSON_THROW_ON_ERROR),
            'source_hash' => hash('sha256', 'recorded-after-cutoff'),
        ]);

        $source = new PortfolioLiquidityAsOfSource(new PaymentCalendarSourceService);
        $result = $source->read(
            $organizationId,
            new PaymentCalendarSourceFilters(
                $organizationId,
                '2026-07-30',
                '2026-07-30',
                currency: 'RUB',
            ),
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        self::assertSame('100.00', $result['balances']['RUB']->amount);
        self::assertCount(1, $result['versions']);
    }

    #[Test]
    public function result_rejects_snapshot_with_mismatched_pinned_identity(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $snapshotId = '01K1D8YQ7M8H6NK0X8F0S1A2B3';
        $sourceHash = new Sha256Hash(hash('sha256', 'source'));
        $definitionHash = new Sha256Hash(hash('sha256', 'definition'));
        $queryHash = new Sha256Hash(hash('sha256', 'query'));
        DB::table('budgeting_portfolio_report_snapshots')->insert([
            'id' => $snapshotId,
            'organization_id' => 740001,
            'report_code' => BudgetingPortfolioProjectionService::HEALTH_CODE,
            'as_of' => '2026-07-30 12:00:00+00',
            'definition_hash' => $definitionHash->value,
            'source_hash' => $sourceHash->value,
            'query_hash' => $queryHash->value,
            'formula_version' => 'formula-v1',
            'source_schema_version' => 'schema-v1',
            'quality_status' => 'complete',
            'freshness_status' => 'fresh',
            'totals' => json_encode([], JSON_THROW_ON_ERROR),
            'watermarks' => json_encode([], JSON_THROW_ON_ERROR),
            'source_refs' => json_encode([], JSON_THROW_ON_ERROR),
            'row_count' => 1,
            'generated_at' => '2026-07-30 12:00:00+00',
            'stale_at' => null,
        ]);
        $scope = new ReportScope(740001, [740001], [], [], new DateTimeZone('UTC'));
        $context = new ReportExecutionContext(
            new ReportActor(1, 'active', ['reports.view']),
            $scope,
            new ReportVisibility(true, false, false, false, false, false, false),
            new AuthorizationDecisionContext(
                'test',
                740001,
                [740001],
                [],
                [],
                new DateTimeZone('UTC'),
                'identity-test',
                null,
            ),
        );
        $snapshot = new ReportSnapshotRef(
            BudgetingPortfolioProjectionService::HEALTH_CODE,
            $snapshotId,
            $scope,
            new Sha256Hash(hash('sha256', 'wrong-definition')),
            'formula-v1',
            $sourceHash,
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
            null,
            ['query_hash' => $queryHash->value],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );

        $this->expectException(ReportContractException::class);
        app(BudgetingPortfolioProjectionService::class)->result(
            $context,
            $snapshot,
            BudgetingPortfolioProjectionService::HEALTH_CODE,
        );
    }
}
