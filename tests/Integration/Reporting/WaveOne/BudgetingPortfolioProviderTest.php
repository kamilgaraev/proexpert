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
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityBackfillRunner;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquiditySourceVersionBackfill;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
            new DateTimeImmutable('2026-07-30T12:00:00+00:00'),
        );

        self::assertSame('100.00', $result['balances']['RUB']->amount);
        self::assertCount(1, $result['versions']);
    }

    #[Test]
    public function historical_as_of_uses_late_backfill_known_at_explicit_ingestion_cutoff(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $organizationId = 730002;
        DB::table('budgeting_portfolio_liquidity_source_versions')->insert([
            'organization_id' => $organizationId,
            'source_type' => 'opening_balance',
            'source_id' => 'late-balance',
            'source_version' => hash('sha256', 'late-backfill'),
            'occurred_at' => '2026-06-01 10:00:00+00',
            'created_at' => '2026-06-01 10:00:00+00',
            'recorded_at' => '2026-07-30 10:00:00+00',
            'effective_at' => '2026-06-01 00:00:00+00',
            'payload' => json_encode([
                'kind' => 'opening_balance',
                'id' => 'late-balance',
                'organization_id' => $organizationId,
                'balance_date' => '2026-06-01',
                'currency' => 'RUB',
                'amount' => '250.00',
                'status' => 'approved',
            ], JSON_THROW_ON_ERROR),
            'source_hash' => hash('sha256', 'late-backfill'),
        ]);

        $result = (new PortfolioLiquidityAsOfSource(new PaymentCalendarSourceService))->read(
            $organizationId,
            new PaymentCalendarSourceFilters($organizationId, '2026-06-01', '2026-06-30', currency: 'RUB'),
            new DateTimeImmutable('2026-06-30T23:59:59+00:00'),
            new DateTimeImmutable('2026-07-30T11:00:00+00:00'),
        );

        self::assertSame('250.00', $result['balances']['RUB']->amount);
        self::assertSame('2026-07-30T11:00:00+00:00', $result['ingestion_watermark']);
    }

    #[Test]
    public function historical_as_of_prefers_business_occurrence_before_ingestion_order(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $organizationId = 730003;
        foreach ([
            ['version' => 'newer-business', 'occurred' => '2026-06-20 10:00:00+00', 'recorded' => '2026-07-01 10:00:00+00', 'amount' => '300.00'],
            ['version' => 'older-business-late', 'occurred' => '2026-06-10 10:00:00+00', 'recorded' => '2026-07-20 10:00:00+00', 'amount' => '100.00'],
        ] as $version) {
            DB::table('budgeting_portfolio_liquidity_source_versions')->insert([
                'organization_id' => $organizationId,
                'source_type' => 'opening_balance',
                'source_id' => 'ordered-balance',
                'source_version' => hash('sha256', $version['version']),
                'occurred_at' => $version['occurred'],
                'created_at' => $version['occurred'],
                'recorded_at' => $version['recorded'],
                'effective_at' => '2026-06-01 00:00:00+00',
                'payload' => json_encode([
                    'kind' => 'opening_balance',
                    'id' => 'ordered-balance',
                    'organization_id' => $organizationId,
                    'balance_date' => '2026-06-01',
                    'currency' => 'RUB',
                    'amount' => $version['amount'],
                    'status' => 'approved',
                ], JSON_THROW_ON_ERROR),
                'source_hash' => hash('sha256', $version['version']),
            ]);
        }

        $result = (new PortfolioLiquidityAsOfSource(new PaymentCalendarSourceService))->read(
            $organizationId,
            new PaymentCalendarSourceFilters($organizationId, '2026-06-01', '2026-06-30', currency: 'RUB'),
            new DateTimeImmutable('2026-06-30T23:59:59+00:00'),
            new DateTimeImmutable('2026-07-30T11:00:00+00:00'),
        );

        self::assertSame('300.00', $result['balances']['RUB']->amount);
        self::assertCount(1, $result['versions']);
    }

    #[Test]
    public function durable_backfill_resumes_every_canonical_source_from_its_checkpoint(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $organizationId = 730004;
        $backfill = app(PortfolioLiquiditySourceVersionBackfill::class);
        $runner = app(PortfolioLiquidityBackfillRunner::class);
        foreach ($backfill->supportedSourceTypes() as $sourceType) {
            DB::table('budgeting_portfolio_liquidity_backfill_checkpoints')->insert([
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'source_cursor' => 900000000,
                'status' => 'failed',
                'lease_token' => null,
                'lease_expires_at' => null,
                'ingestion_started_at' => '2026-07-30 09:00:00+00',
                'completed_at' => null,
                'failure_code' => 'interrupted',
                'created_at' => '2026-07-30 09:00:00+00',
                'updated_at' => '2026-07-30 09:00:00+00',
            ]);

            $result = $runner->runChunk($organizationId, $sourceType, 10);

            self::assertFalse($result['has_more']);
            self::assertNull($result['next_cursor']);
            self::assertSame(
                900000000,
                DB::table('budgeting_portfolio_liquidity_backfill_checkpoints')
                    ->where('organization_id', $organizationId)
                    ->where('source_type', $sourceType)
                    ->value('source_cursor'),
            );
            self::assertSame(
                'completed',
                DB::table('budgeting_portfolio_liquidity_backfill_checkpoints')
                    ->where('organization_id', $organizationId)
                    ->where('source_type', $sourceType)
                    ->value('status'),
            );
        }
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
