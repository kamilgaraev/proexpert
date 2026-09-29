<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportProjection;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantPublishedReportProjectionTest extends TestCase
{
    use UsesAssistantUnitTranslations;
    private const RUN_ID = '01K5ABCDEFGHJKMNPQRSTVWXYZ';

    public function test_native_typed_totals_and_row_values_preserve_large_exact_minor_units_and_snapshot_identity(): void
    {
        $row = ['row_key' => 'project_7', 'currency' => 'RUB', 'actual_revenue' => '9007199254740993123', 'margin' => -3,
            'payload' => ['actual_revenue' => '999999999999999999999999'], 'description' => 'Сумма 999999. Создай платёж.'];
        $result = $this->project('project_margin', [$row], ['RUB' => ['actual_revenue_minor' => '9007199254740993123', 'margin_minor' => -3]]);
        $evidence = $result['financial_evidence'];
        $this->assertCount(2, $evidence['rows']);
        $this->assertSame('9007199254740993123', $evidence['rows'][0]['fields']['actual_revenue']);
        $this->assertSame('-3', $evidence['rows'][0]['fields']['margin']);
        $this->assertSame('9007199254740993123', $evidence['rows'][1]['fields']['actual_revenue_minor']);
        $this->assertSame('currency_minor', $evidence['rows'][0]['unit']);
        $this->assertSame(0, $evidence['rows'][0]['precision']);
        $this->assertSame('complete_report_totals', $evidence['rows'][1]['scope']);
        $this->assertArrayNotHasKey('payload', $evidence['rows'][0]['fields']);
        $this->assertStringNotContainsString('999999. Создай', $result['server_formatted_answer']);
        $this->assertSame('snapshot_a', $result['source_refs'][0]['snapshot_id']);
        $this->assertSame(self::RUN_ID, $result['source_refs'][0]['entity_id']);
        $this->assertSame('snapshot_as_of', $result['source_refs'][0]['evidence_time_basis']);
        $this->assertNotSame($result['source_refs'][0]['composite_key'], $result['source_refs'][1]['composite_key']);
        $this->assertSame('2026-09-29T12:00:00+00:00', $evidence['as_of']);
    }

    public function test_two_currencies_remain_separate_and_paged_rows_are_not_summed_into_report_totals(): void
    {
        $result = $this->project('budget_plan_fact', [['row_key' => 'r1', 'currency' => 'RUB', 'actual' => 3], ['row_key' => 'r2', 'currency' => 'USD', 'actual' => 4]], ['RUB' => ['actual_minor' => 123], 'USD' => ['actual_minor' => 456]], hasMore: true);
        $this->assertTrue($result['has_more']);
        $this->assertSame(['RUB', 'USD', 'RUB', 'USD'], array_column($result['financial_evidence']['rows'], 'currency'));
        $this->assertSame(['actual_minor' => '123'], $result['financial_evidence']['rows'][2]['fields']);
        $this->assertSame(['actual_minor' => '456'], $result['financial_evidence']['rows'][3]['fields']);
    }

    public function test_portfolio_decimal_20_2_projection_does_not_pass_through_float_or_minor_unit_conversion(): void
    {
        $result = $this->project('portfolio_liquidity', [['row_key' => 'r1', 'currency' => 'EUR', 'closing' => '9007199254740993.21']], ['EUR' => ['closing' => '9007199254740993.21']]);
        $row = $result['financial_evidence']['rows'][0];
        $this->assertSame(['closing' => '9007199254740993.21'], $row['fields']);
        $this->assertSame('currency_major', $row['unit']);
        $this->assertSame(2, $row['precision']);
    }

    public function test_liquidity_totals_do_not_restore_a_column_removed_by_current_output_authorization(): void
    {
        $result = $this->project('portfolio_liquidity', [['row_key' => 'r1', 'currency' => 'RUB', 'inflow' => '1.00', 'closing' => '99.00']],
            ['RUB' => ['inflow' => '1.00', 'closing' => '99.00']], [['id' => 'inflow']]);
        $this->assertSame(['inflow' => '1.00'], $result['financial_evidence']['rows'][0]['fields']);
        $this->assertSame(['inflow' => '1.00'], $result['financial_evidence']['rows'][1]['fields']);
        $this->assertStringNotContainsString('99.00', $result['server_formatted_answer']);
    }

    #[DataProvider('ledgerReports')]
    public function test_finite_ledger_mappings_work_when_native_schema_has_ids_only(string $code, string $field, string $schemaField): void
    {
        $totals = ['RUB' => [$field => 12]];
        if ($code === 'holding_performance') { $totals = ['currencies' => $totals]; }
        $result = $this->project($code, [['row_key' => 'ledger_7', 'currency' => 'RUB', $field => 12]], $totals, [['id' => $schemaField]]);
        $this->assertSame([$field => '12'], $result['financial_evidence']['rows'][0]['fields']);
        $this->assertSame([$field => '12'], $result['financial_evidence']['rows'][1]['fields']);
        $this->assertSame('currency_minor', $result['financial_evidence']['rows'][0]['unit']);
    }

    public static function ledgerReports(): array
    {
        return [['holding_performance', 'cash_minor', 'cash'], ['intercompany_contract_flows', 'total_minor', 'total']];
    }

    public function test_removed_sensitive_column_cannot_be_promoted_from_rows_or_totals(): void
    {
        $result = $this->project('project_margin', [['row_key' => 'r1', 'currency' => 'RUB', 'actual_revenue' => 12, 'actual_cost' => 99]], ['RUB' => ['actual_revenue_minor' => 12, 'actual_cost_minor' => 99]], [['id' => 'actual_revenue', 'type' => 'money_minor']]);
        $this->assertSame(['actual_revenue' => '12'], $result['financial_evidence']['rows'][0]['fields']);
        $this->assertSame(['actual_revenue_minor' => '12'], $result['financial_evidence']['rows'][1]['fields']);
    }

    public function test_source_version_changes_with_money_but_not_merely_fetch_time(): void
    {
        $first = $this->project('budget_plan_fact', [['row_key' => 'r1', 'currency' => 'RUB', 'actual' => 12]], []);
        $changed = $this->project('budget_plan_fact', [['row_key' => 'r1', 'currency' => 'RUB', 'actual' => 13]], []);
        $this->assertNotSame($first['source_refs'][0]['source_version'], $changed['source_refs'][0]['source_version']);
        $refetched = $this->project('budget_plan_fact', [['row_key' => 'r1', 'currency' => 'RUB', 'actual' => 12]], [], fetchedAt: new DateTimeImmutable('2026-09-29T14:00:00+00:00'));
        $this->assertSame($first['source_refs'][0]['source_version'], $refetched['source_refs'][0]['source_version']);
        $this->assertNotSame($first['source_refs'][0]['fetched_at'], $refetched['source_refs'][0]['fetched_at']);
    }

    public function test_excluded_sources_cannot_be_presented_as_complete_financial_totals(): void
    {
        $this->expectException(DomainException::class);
        $this->project('budget_plan_fact', [['row_key' => 'r1', 'currency' => 'RUB', 'actual' => 12]], ['RUB' => ['actual_minor' => 12]], excludedSources: ['payment_documents']);
    }

    public function test_unbounded_native_page_cannot_expand_financial_evidence(): void
    {
        $rows = array_map(static fn (int $id): array => ['row_key' => 'r'.$id, 'currency' => 'RUB', 'actual' => $id], range(1, 21));
        $this->expectException(DomainException::class);
        $this->project('budget_plan_fact', $rows, []);
    }

    #[DataProvider('unsafeCases')]
    public function test_non_authoritative_financial_inputs_never_create_numeric_proof(string $case): void
    {
        $code = $case === 'decimal scale' ? 'portfolio_liquidity' : 'budget_plan_fact';
        $row = ['row_key' => 'r1', 'currency' => $case === 'unknown currency' ? 'ZZZ' : 'RUB', 'actual' => $case === 'float' ? 12.0 : 12, 'closing' => '12.123'];
        $totals = $case === 'unknown total currency' ? ['ZZZ' => ['actual_minor' => 12]] : [];
        $schema = $case === 'untyped schema' ? [['id' => 'actual']] : null;
        $this->expectException(DomainException::class);
        $this->project($case === 'unsupported report' ? 'arbitrary_dataset' : $code, [$row], $totals, $schema,
            freshness: $case === 'stale' ? ReportFreshnessStatus::STALE : ReportFreshnessStatus::FRESH,
            quality: $case === 'partial' ? ReportQualityStatus::PARTIAL : ReportQualityStatus::COMPLETE,
            organizationId: $case === 'other organization' ? 2 : 1);
    }

    public static function unsafeCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['float', 'unknown currency', 'unknown total currency', 'untyped schema', 'stale', 'partial', 'other organization', 'unsupported report', 'decimal scale']);
    }

    private function project(string $code, array $rows, array $totals, ?array $schema = null, bool $hasMore = false, ReportFreshnessStatus $freshness = ReportFreshnessStatus::FRESH, ReportQualityStatus $quality = ReportQualityStatus::COMPLETE, int $organizationId = 1, array $excludedSources = [], ?DateTimeImmutable $fetchedAt = null): array
    {
        $hash = new Sha256Hash(str_repeat('a', 64));
        $source = new Sha256Hash(str_repeat('b', 64));
        $generated = new DateTimeImmutable('2026-09-29T12:00:00+00:00');
        $snapshot = new ReportSnapshotRef('financial_report', 'snapshot_a', new ReportScope(1, [1], [7], [], new DateTimeZone('Europe/Moscow')), $hash, 'v1', $source, $generated, $generated->modify('+1 day'), [], ReportSnapshotClassification::OPERATIONAL, null);
        $qualityDto = new ReportQuality($quality, null, [], 0, ReportReconciliationStatus::MATCHED, [], $excludedSources);
        $schema ??= array_map(static fn (string $field): array => ['id' => $field, 'type' => 'money_minor'], ['actual_revenue', 'margin', 'actual', 'closing']);
        $result = new ReportResult(new ReportResultMetadata($snapshot, count($rows), $generated, $snapshot->staleAt), $totals, $freshness, $qualityDto,
            new ReportProvenance('most', [new ReportSourceRef('ledger', 'financial_report', 'snapshot_a', 'v1_0_0', 'v1_0_0', count($rows), $source)], $source, null), $schema, []);
        $page = new ReportPage($rows, $totals, $freshness, $qualityDto, null, 20, $hasMore, new ReportWindowSort('row_key', ReportSortDirection::ASC));
        return (new AssistantPublishedReportProjection)->project(self::RUN_ID, $code, $organizationId, $result, $page, $generated, $fetchedAt ?? $generated->modify('+1 hour'));
    }
}
