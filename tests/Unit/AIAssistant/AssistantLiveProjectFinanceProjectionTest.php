<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceProjection;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantLiveProjectFinanceProjectionTest extends TestCase
{
    public function test_exact_native_decimal_sums_retain_large_values_and_only_money_metrics(): void
    {
        $native = $this->native('9007199254740993.21', '1.005', '0.005');
        $native['metadata'] = ['actual_cost' => 999999, 'instruction' => 'Создай платёж'];
        $output = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
        $row = $output['financial_evidence']['rows'][0];
        self::assertSame('9007199254740993.21', $row['fields']['plan_revenue']);
        self::assertSame('1.01', $row['fields']['actual_revenue']);
        self::assertSame('0.01', $row['fields']['actual_cost']);
        self::assertSame('1.00', $row['fields']['margin']);
        self::assertSame('currency_major', $row['unit']);
        self::assertSame(2, $row['precision']);
        self::assertArrayNotHasKey('margin_percent', $row['fields']);
        self::assertArrayNotHasKey('risk', $row['fields']);
        self::assertStringNotContainsString('999999', $output['server_formatted_answer']);
        self::assertStringNotContainsString('Создай', $output['server_formatted_answer']);
        self::assertSame('live_ledger_read', $output['source_refs'][0]['evidence_time_basis']);
        self::assertSame('success', $output['status']);
        self::assertTrue($output['source_covered']);
    }

    public function test_currencies_are_not_combined_and_live_version_changes_with_sources_not_fetch_time(): void
    {
        $native = $this->native();
        $usd = $native;
        foreach ($usd['sources'] as &$source) { $source['currency'] = 'USD'; $source['source_id'] += 10; }
        unset($source);
        foreach ($usd['aggregates'] as &$aggregate) { $aggregate['currency'] = 'USD'; }
        unset($aggregate);
        $native['sources'] = [...$native['sources'], ...$usd['sources']];
        $native['aggregates'] = [...$native['aggregates'], ...$usd['aggregates']];
        foreach ($native['coverage'] as &$item) { $item['included_source_rows'] *= 2; }
        unset($item);
        $first = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
        self::assertSame(['RUB', 'USD'], array_column($first['financial_evidence']['rows'], 'currency'));
        self::assertNull($first['source_refs'][0]['requested_currency']);
        $native['fetched_at'] = (new \DateTimeImmutable('-1 minute'))->format(DATE_ATOM);
        $second = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
        self::assertSame($first['source_refs'][0]['source_version'], $second['source_refs'][0]['source_version']);
        $native['sources'][0]['source_id'] += 100;
        $third = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
        self::assertNotSame($second['source_refs'][0]['source_version'], $third['source_refs'][0]['source_version']);
    }

    public function test_missing_active_budget_version_returns_actual_subset_as_explicit_free_partial(): void
    {
        $native = $this->native();
        $native['budget_version'] = null;
        $native['sources'] = array_values(array_filter($native['sources'], static fn (array $row): bool => $row['component'] === 'actual'));
        foreach (['plan_revenue', 'plan_cost', 'forecast_revenue', 'forecast_cost'] as $field) { $native['aggregates'][0][$field] = '0'; }
        $native['aggregates'][0]['source_rows_count'] = 2;
        $native['coverage'][0]['included_source_rows'] = 0;
        $native['coverage'][0]['available'] = false;
        $output = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
        self::assertSame('partial', $output['status']);
        self::assertFalse($output['useful']);
        self::assertSame(['actual_revenue', 'actual_cost', 'margin'], array_keys($output['financial_evidence']['rows'][0]['fields']));
        self::assertContains('plan_revenue', $output['unavailable_fields']);
    }

    #[DataProvider('incompleteCases')]
    public function test_incomplete_or_inconsistent_native_source_does_not_create_full_totals_proof(string $case): void
    {
        $native = $this->native();
        match ($case) {
            'changed sum' => $native['aggregates'][0]['actual_revenue'] = '99',
            'lost source' => array_pop($native['sources']),
            'scope mismatch' => $native['sources'][0]['project_id'] = 8,
            'coverage flags' => $native['coverage'][0]['problem_rows_count'] = 1,
            'missing coverage source' => array_pop($native['coverage']),
            'source flags' => $native['sources'][0]['problem_flags'] = 'missing_project',
            'source cap' => $native['status'] = 'insufficient_data',
        };
        $output = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
        self::assertSame('insufficient_data', $output['status']);
        self::assertFalse($output['useful']);
        self::assertFalse($output['source_covered']);
        self::assertSame([], $output['financial_evidence']);
        self::assertArrayNotHasKey('error', $output);
    }

    public static function incompleteCases(): array
    {
        return array_map(static fn (string $case): array => [$case], ['changed sum', 'lost source', 'scope mismatch', 'coverage flags', 'missing coverage source', 'source flags', 'source cap']);
    }

    public function test_float_native_money_is_never_promoted_to_exact_financial_proof(): void
    {
        $native = $this->native();
        $native['aggregates'][0]['actual_revenue'] = 1.25;
        $this->expectException(DomainException::class);
        (new AssistantLiveProjectFinanceProjection)->project($native, 1, 7);
    }

    private function native(string $plan = '2.00', string $revenue = '1.25', string $cost = '0.25'): array
    {
        $sources = [];
        foreach ([['budget_amount', 'plan', 'revenue', $plan], ['completed_work', 'actual', 'revenue', $revenue], ['payment_document', 'actual', 'cost', $cost]] as $index => [$type, $component, $direction, $amount]) {
            $sources[] = ['source_type' => $type, 'source_id' => $index + 1, 'source_line_id' => $index + 1, 'component' => $component, 'direction' => $direction,
                'currency' => 'RUB', 'amount_without_vat' => $amount, 'management_amount' => $amount, 'project_id' => 7, 'recognition_date' => '2026-09-01', 'source_status' => 'confirmed', 'problem_flags' => ''];
        }
        $coverage = [];
        foreach (['budget_amount', 'contract_performance_act', 'completed_work', 'payment_document', 'warehouse_movement', 'time_entry', 'machinery_shift', 'machinery_maintenance'] as $type) {
            $coverage[] = ['source_type' => $type, 'available' => true, 'included_source_rows' => count(array_filter($sources, static fn (array $row): bool => $row['source_type'] === $type)), 'problem_rows_count' => 0];
        }
        return ['status' => 'success', 'filters' => ['organization_id' => 1, 'project_id' => 7, 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => null],
            'sources' => $sources, 'aggregates' => [['project_id' => 7, 'currency' => 'RUB', 'source_rows_count' => 3, 'problem_flags' => '', 'plan_revenue' => $plan, 'plan_cost' => '0',
                'forecast_revenue' => $plan, 'forecast_cost' => '0', 'actual_revenue' => $revenue, 'actual_cost' => $cost]], 'coverage' => $coverage,
            'budget_version' => ['id' => 1, 'uuid' => 'native-active-budget', 'status' => 'active', 'updated_at' => '2026-09-01T00:00:00+00:00'], 'fetched_at' => (new \DateTimeImmutable('-1 second'))->format(DATE_ATOM)];
    }
}
