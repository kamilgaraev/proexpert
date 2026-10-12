<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceProjection;
use PHPUnit\Framework\TestCase;

final class AssistantProjectExpenseProofTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    private const QUERY = 'Покажи расходы нашего объекта за сентябрь 2025 года.';

    public function test_project_status_cannot_answer_requested_expenses(): void
    {
        $result = (new AssistantStructuredFactVerifier)->guard(self::QUERY, 'Проект активен.', [], ['project_id' => 52]);
        self::assertTrue($result['needs_clarification']);
        self::assertSame([], $result['source_refs']);
        self::assertStringNotContainsString('активен', $result['text']);
        $terminal = (new AssistantStructuredFactVerifier)->confirmedResults([], self::QUERY, ['project_id' => 52]);
        self::assertTrue($terminal['needs_clarification']);
    }

    public function test_actual_cost_projection_answers_exact_requested_project_period_without_plan_budget(): void
    {
        $read = $this->read();
        self::assertSame('partial', $read['status']);
        $proof = (new AssistantStructuredFactVerifier)->guard(self::QUERY, 'Проект активен.', [$read], ['project_id' => 52]);
        self::assertSame('verified', $proof['validation_status']);
        self::assertFalse($proof['needs_clarification']);
        self::assertStringContainsString('60.00 RUB', $proof['text']);
        self::assertStringContainsString('2025-09-01', $proof['text']);
        self::assertStringContainsString('2025-09-30', $proof['text']);
        self::assertSame($read['source_refs'], $proof['source_refs']);
        $terminal = (new AssistantStructuredFactVerifier)->confirmedResults([$read], self::QUERY, ['project_id' => 52]);
        self::assertSame('verified', $terminal['validation_status']);
    }

    public function test_wrong_project_year_and_unknown_current_scope_cannot_complete_expense_answer(): void
    {
        foreach ([[], ['project_id' => 53]] as $scope) {
            self::assertTrue((new AssistantStructuredFactVerifier)->guard(self::QUERY, '', [$this->read()], $scope)['needs_clarification']);
        }
        self::assertTrue((new AssistantStructuredFactVerifier)->guard('Покажи расходы нашего объекта за сентябрь 2024 года.', '', [$this->read()], ['project_id' => 52])['needs_clarification']);
        self::assertTrue((new AssistantStructuredFactVerifier)->guard('Покажи расходы нашего объекта.', '', [$this->read()], ['project_id' => 52])['needs_clarification']);
        self::assertTrue((new AssistantStructuredFactVerifier)->guard('Покажи плановые расходы нашего объекта за сентябрь 2025 года.', '', [$this->read()], ['project_id' => 52])['needs_clarification']);
    }

    public function test_currencies_are_rendered_independently_and_unsigned_modified_values_are_rejected(): void
    {
        $native = $this->native();
        $usd = $native;
        foreach ($usd['sources'] as &$source) { $source['currency'] = 'USD'; $source['source_id'] += 10; }
        unset($source);
        $usd['aggregates'][0]['currency'] = 'USD';
        $native['sources'] = [...$native['sources'], ...$usd['sources']];
        $native['aggregates'] = [...$native['aggregates'], ...$usd['aggregates']];
        foreach ($native['coverage'] as &$coverage) { $coverage['included_source_rows'] *= 2; }
        unset($coverage);
        $read = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 52);
        $read['_tool_name'] = 'get_live_project_financial_evidence';
        $proof = (new AssistantStructuredFactVerifier)->guard(self::QUERY, '', [$read], ['project_id' => 52]);
        self::assertStringContainsString('60.00 RUB', $proof['text']);
        self::assertStringContainsString('60.00 USD', $proof['text']);
        self::assertStringNotContainsString('120.00', $proof['text']);
        $read['financial_evidence']['rows'][0]['fields']['actual_cost'] = '99.00';
        self::assertTrue((new AssistantStructuredFactVerifier)->guard(self::QUERY, '', [$read], ['project_id' => 52])['needs_clarification']);
    }

    public function test_one_project_cannot_prove_expenses_for_all_projects(): void
    {
        $query = 'Покажи расходы по всем проектам за сентябрь 2025 года.';
        foreach (['guard', 'confirmedResults'] as $method) {
            $verifier = new AssistantStructuredFactVerifier;
            $proof = $method === 'guard' ? $verifier->guard($query, '', [$this->read()], ['project_id' => 52])
                : $verifier->confirmedResults([$this->read()], $query, ['project_id' => 52]);
            self::assertTrue($proof['needs_clarification']);
            self::assertSame([], $proof['source_refs']);
        }
    }

    private function read(): array
    {
        return (new AssistantLiveProjectFinanceProjection)->project($this->native(), 1, 52)
            + ['_tool_name' => 'get_live_project_financial_evidence'];
    }

    public function test_empty_authorized_period_proves_no_records_without_inventing_money_or_currency(): void
    {
        $read = $this->emptyRead();
        $proof = (new AssistantStructuredFactVerifier)->guard(self::QUERY, '0 RUB', [$read], ['project_id' => 52]);
        self::assertSame('success', $read['status']);
        self::assertTrue($read['useful']);
        self::assertSame('verified', $proof['validation_status']);
        self::assertFalse($proof['needs_clarification']);
        self::assertSame($read['source_refs'], $proof['source_refs']);
        self::assertStringContainsString('расходные записи не найдены', $proof['text']);
        self::assertStringContainsString('2025-09-01', $proof['text']);
        self::assertStringNotContainsString('RUB', $proof['text']);
        self::assertStringNotContainsString('0.00', $proof['text']);
        self::assertTrue((new AssistantStructuredFactVerifier)->guard(self::QUERY, '', [$read], ['project_id' => 53])['needs_clarification']);
        self::assertTrue((new AssistantStructuredFactVerifier)->guard('Расходы нашего объекта за сентябрь 2026 года', '', [$read], ['project_id' => 52])['needs_clarification']);
    }

    public function test_changed_or_stale_empty_period_proof_is_rejected(): void
    {
        foreach (['count', 'source_version', 'time'] as $case) {
            $read = $this->emptyRead();
            if ($case === 'count') { $read['financial_evidence']['absence']['record_count'] = 1; }
            if ($case === 'source_version') { $read['financial_evidence']['absence']['source_ref']['source_version'] = 'changed'; }
            if ($case === 'time') { $read['financial_evidence']['absence']['source_ref']['fetched_at'] = gmdate(DATE_ATOM, time() - 3600); }
            self::assertTrue((new AssistantStructuredFactVerifier)->guard(self::QUERY, '', [$read], ['project_id' => 52])['needs_clarification'], $case);
        }
    }

    public function test_missing_actual_source_coverage_cannot_prove_empty_period(): void
    {
        $native = $this->native();
        $native['sources'] = $native['aggregates'] = [];
        foreach ($native['coverage'] as &$coverage) { $coverage['included_source_rows'] = 0; }
        unset($coverage);
        $native['coverage'][1]['available'] = false;
        $read = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 52);
        self::assertFalse($read['source_covered']);
        self::assertSame([], $read['source_refs']);
    }

    public function test_empty_currency_subset_cannot_prove_absence_of_all_project_expenses(): void
    {
        $native = $this->native();
        $native['sources'] = $native['aggregates'] = [];
        foreach ($native['coverage'] as &$coverage) { $coverage['included_source_rows'] = 0; }
        unset($coverage);
        $native['filters']['currency'] = 'USD';
        $read = (new AssistantLiveProjectFinanceProjection)->project($native, 1, 52);
        self::assertFalse($read['source_covered']);
        self::assertSame([], $read['source_refs']);
        $read = $this->emptyRead();
        $read['financial_evidence']['absence']['source_ref']['requested_currency'] = 'USD';
        self::assertTrue((new AssistantStructuredFactVerifier)->guard(self::QUERY, '', [$read], ['project_id' => 52])['needs_clarification']);
    }

    private function emptyRead(): array
    {
        $native = $this->native();
        $native['sources'] = $native['aggregates'] = [];
        foreach ($native['coverage'] as &$coverage) { $coverage['included_source_rows'] = 0; }
        unset($coverage);

        return (new AssistantLiveProjectFinanceProjection)->project($native, 1, 52)
            + ['_tool_name' => 'get_live_project_financial_evidence'];
    }

    private function native(): array
    {
        $sources = [];
        foreach ([['completed_work', 'revenue', '100'], ['warehouse_movement', 'cost', '60']] as $i => [$type, $direction, $amount]) {
            $sources[] = ['source_type' => $type, 'source_id' => $i + 1, 'project_id' => 52, 'currency' => 'RUB',
                'component' => 'actual', 'direction' => $direction, 'amount_without_vat' => $amount, 'management_amount' => $amount, 'problem_flags' => ''];
        }
        $coverage = [];
        foreach (['budget_amount', 'contract_performance_act', 'completed_work', 'payment_document', 'warehouse_movement', 'time_entry', 'machinery_shift', 'machinery_maintenance'] as $type) {
            $coverage[] = ['source_type' => $type, 'available' => $type !== 'budget_amount', 'problem_rows_count' => 0,
                'included_source_rows' => in_array($type, ['completed_work', 'warehouse_movement'], true) ? 1 : 0];
        }
        return ['status' => 'success', 'filters' => ['organization_id' => 1, 'project_id' => 52,
            'period_start' => '2025-09-01', 'period_end' => '2025-09-30', 'currency' => null], 'budget_version' => null,
            'sources' => $sources, 'coverage' => $coverage, 'fetched_at' => gmdate(DATE_ATOM), 'aggregates' => [[
                'project_id' => 52, 'currency' => 'RUB', 'source_rows_count' => 2, 'problem_flags' => '',
                'plan_revenue' => '0', 'plan_cost' => '0', 'forecast_revenue' => '0', 'forecast_cost' => '0', 'actual_revenue' => '100', 'actual_cost' => '60',
            ]]];
    }
}
