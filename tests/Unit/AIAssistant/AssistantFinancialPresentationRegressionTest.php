<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainNumericEvidence;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;
use App\BusinessModules\Features\AIAssistant\Services\AssistantPresentationPlanner;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateStructuredFacts;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantFinancialPresentationRegressionTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_model_can_select_one_proven_price_and_leave_unrelated_read_results_out(): void
    {
        $positions = $this->positions();
        $other = $this->receipt(99, 'estimate', ['name' => 'Общая смета']);
        $result = (new AssistantPresentationPlanner)->render($this->pricePlan($positions), [$other, $positions], 'Найди в любой смете цену за 1м3 бетона');

        self::assertNotNull($result);
        self::assertStringContainsString('6100.0000', $result);
        self::assertStringContainsString('м³', $result);
        self::assertStringNotContainsString('Кирпич', $result);
        self::assertStringNotContainsString('Общая смета', $result);
    }

    public function test_financial_guard_preserves_the_checked_model_selection_instead_of_snapshot_totals(): void
    {
        $positions = $this->positions();
        $positions += AssistantDomainNumericEvidence::payload($positions['structured_fact_evidence']['rows'], '2026-10-03T18:39:00Z');
        $snapshot = ['server_formatted_answer' => 'Сумма сметы: 53350561.75 руб.', 'financial_evidence' => [
            'fetched_at' => '2026-10-03T18:39:00Z', 'version' => 'snapshot', 'validation_status' => 'verified',
            'source_refs' => [['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 3]],
        ]];
        $result = (new AssistantFinancialClaimVerifier)->guard('Цена за единицу: 6100.0000 RUB', [$snapshot, $positions],
            $this->pricePlan($positions), 'Найди в любой смете цену за 1м3 бетона');

        self::assertStringContainsString('6100.0000', $result['text']);
        self::assertStringNotContainsString('53350561.75', $result['text']);
        self::assertCount(1, $result['source_refs']);
        self::assertSame(91, $result['source_refs'][0]['entity_id']);
    }

    public function test_position_bridge_exposes_the_read_unit_price_and_measurement_unit(): void
    {
        $position = ['id' => 91, 'position_number' => '1', 'name' => 'Бетон В25', 'quantity' => '2.00000000',
            'unit_price' => '6100.0000', 'unit' => 'м³', 'total_amount' => '12200.00', 'version' => 'position-v1'];
        $source = ['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 3, 'project_id' => 7,
            'fetched_at' => '2026-10-03T18:39:00Z', 'version' => 'snapshot', 'source_version' => 'current',
            'checked_fields' => ['number', 'name', 'status', 'estimate_date'],
            'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view']];
        $evidence = ['estimate' => ['id' => 99, 'number' => 'СМ-99', 'name' => 'Смета', 'status' => 'approved', 'estimate_date' => '2026-09-09'],
            'positions' => [$position], 'position_count' => 1, 'source_refs' => [$source], 'fetched_at' => $source['fetched_at'], 'version' => 'snapshot'];
        $result = AssistantEstimateStructuredFacts::positions($evidence, [$position], 3);
        $fields = $result['structured_fact_evidence']['rows'][1]['fields'];

        self::assertSame('6100.0000', $fields['unit_price'] ?? null);
        self::assertSame('м³', $fields['unit'] ?? null);
        self::assertArrayHasKey('currency', $fields);
        self::assertNull($fields['currency']);
    }

    public function test_complete_estimate_snapshot_exposes_its_aggregate_total_without_confusing_it_with_unit_price(): void
    {
        $source = ['entity_type' => 'estimate', 'entity_id' => 99, 'organization_id' => 3, 'project_id' => 7,
            'content_scope' => 'structured',
            'fetched_at' => '2026-10-03T18:39:00Z', 'version' => 'snapshot', 'source_version' => 'current',
            'checked_fields' => ['number', 'name', 'status', 'estimate_date', 'total_amount'],
            'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view']];
        $evidence = ['estimate' => ['id' => 99, 'number' => 'СМ-99', 'name' => 'Смета', 'status' => 'approved', 'estimate_date' => '2026-09-09'],
            'totals' => ['total_amount' => '9007199254740993.17'], 'aggregation' => ['method' => 'sum_top_level_accounted_positions'],
            'source_refs' => [$source], 'fetched_at' => $source['fetched_at'], 'version' => 'snapshot', 'validation_status' => 'partial'];
        $receipt = AssistantEstimateStructuredFacts::identity($evidence, 3);
        $receipt['financial_evidence'] = $evidence;
        $plan = $this->plan($receipt, ['number', 'total_amount', 'currency']);
        self::assertNotNull((new AssistantPresentationPlanner)->render($plan, [$receipt], 'Общая сумма сметы'));
        self::assertNotNull((new AssistantPresentationPlanner)->render($plan, [$receipt], 'Стоимость на сегодня'));
        self::assertFalse(AssistantFactIntentClassifier::requiresUnitPrice('Общая стоимость за сентябрь'));
        foreach (['Стоимость одной сметы', 'Стоимость одного договора', 'Цена 1 октября'] as $query) {
            self::assertFalse(AssistantFactIntentClassifier::requiresUnitPrice($query), $query);
        }
        $result = (new AssistantFinancialClaimVerifier)->guard('Сумма 1', [$receipt], $plan, 'Общая сумма сметы');

        self::assertStringContainsString('9007199254740993.17', $result['text']);
        self::assertSame([99], array_column($result['source_refs'], 'entity_id'));
        self::assertNull((new AssistantPresentationPlanner)->render($plan, [$receipt], 'Цена за 1м3 бетона'));
        unset($evidence['aggregation']);
        $page = AssistantEstimateStructuredFacts::identity($evidence, 3);
        self::assertArrayNotHasKey('total_amount', $page['structured_fact_evidence']['rows'][0]['fields']);
    }

    public function test_selected_price_survives_more_than_twenty_five_unrelated_search_rows(): void
    {
        $results = [];
        for ($id = 100; $id < 130; $id++) {
            $results[] = $this->receipt($id, 'project', ['name' => 'Посторонний объект '.$id]);
        }
        $positions = $this->positions();
        $results[] = $positions;
        $result = (new AssistantStructuredFactVerifier)->guard('Найди в любой смете цену за 1м3 бетона', $this->pricePlan($positions), $results);

        self::assertStringContainsString('6100.0000', $result['text']);
        self::assertStringNotContainsString('Посторонний', $result['text']);
        self::assertCount(1, $result['source_refs']);
        self::assertSame(91, $result['source_refs'][0]['entity_id']);
    }

    public function test_every_catalog_entity_preserves_selected_checked_fields_and_sources(): void
    {
        foreach (AssistantDomainCatalog::defaults() as $definition) {
            $field = current(array_intersect(['name', 'title', 'number', 'document_number', 'status', 'code', 'subject', 'quality_status', 'freshness_status'], $definition->fields));
            self::assertNotFalse($field, $definition->domain);
            foreach ($definition->entityTypes as $type) {
                $first = $this->receipt(1, $type, [$field => 'Выбранная запись']);
                $second = $this->receipt(2, $type, [$field => 'Лишняя запись']);
                $both = AssistantStructuredFactFormatter::payload(array_merge($first['structured_fact_evidence']['rows'], $second['structured_fact_evidence']['rows']), '2026-10-03T18:39:00Z');
                $plan = $this->plan($both, [$field]);
                $result = (new AssistantStructuredFactVerifier)->guard('Покажи любой пример', $plan, [$both]);

                self::assertStringContainsString('Выбранная запись', $result['text'], $type);
                self::assertStringNotContainsString('Лишняя запись', $result['text'], $type);
                self::assertSame([1], array_column($result['source_refs'], 'entity_id'), $type);
            }
        }
    }

    public function test_money_selection_is_verified_for_contracts_payments_procurement_works_and_projects(): void
    {
        foreach (['contract' => 'total_amount', 'payment_document' => 'amount', 'purchase_order' => 'total_amount',
            'completed_work' => 'total_amount', 'project' => 'budget_amount'] as $type => $field) {
            $receipt = $this->receipt(91, $type, [$field => '9007199254740993.17', 'currency' => 'RUB']);
            $receipt += AssistantDomainNumericEvidence::payload($receipt['structured_fact_evidence']['rows'], '2026-10-03T18:39:00Z');
            $unrelated = $this->receipt(92, 'contract', ['total_amount' => '42.00', 'currency' => 'EUR']);
            $unrelated += AssistantDomainNumericEvidence::payload($unrelated['structured_fact_evidence']['rows'], '2026-10-03T18:39:00Z');
            $plan = $this->plan($receipt, [$field, 'currency']);
            $result = (new AssistantFinancialClaimVerifier)->guard('Сумма 1', [$unrelated, $receipt], $plan, 'Какая сумма?');

            self::assertStringContainsString('9007199254740993.17', $result['text'], $type);
            self::assertStringNotContainsString('42.00', $result['text'], $type);
            self::assertSame([91], array_column($result['source_refs'], 'entity_id'), $type);
        }
    }

    public function test_price_never_uses_totals_or_another_read_version_as_its_proof(): void
    {
        $positions = $this->positions();
        $positions += AssistantDomainNumericEvidence::payload($positions['structured_fact_evidence']['rows'], '2026-10-03T18:39:00Z');
        $plan = $this->pricePlan($positions);
        foreach (['fetched_at', 'source_version', 'organization_id', 'checked_fields'] as $field) {
            $tampered = $positions;
            $tampered['financial_evidence']['source_refs'][0][$field] = match ($field) {
                'organization_id' => 4, 'checked_fields' => ['total_amount'], default => 'old',
            };
            $result = (new AssistantFinancialClaimVerifier)->guard('Цена 6100', [$tampered], $plan, 'Цена за 1м3 бетона');
            self::assertSame([], $result['source_refs'], $field);
            self::assertStringNotContainsString('6100', $result['text'], $field);
        }
        $total = $this->receipt(99, 'estimate', ['total_amount' => '53350561.75', 'currency' => 'RUB']);
        foreach (['Найди в любой смете цену за 1м3 бетона', 'Найди в любой смете цену на 1м3 бетона', 'Стоимость 1 м³ бетона', 'Цена одного кубометра бетона', 'Стоимость единицы материала'] as $query) {
            self::assertTrue(AssistantFactIntentClassifier::requiresUnitPrice($query), $query);
            self::assertNull((new AssistantPresentationPlanner)->render($this->plan($total, ['total_amount', 'currency']), [$total], $query));
        }
    }

    public function test_price_displays_unknown_currency_explicitly_and_requires_a_read_measurement_unit(): void
    {
        $receipt = $this->receipt(91, 'estimate_item', ['name' => 'Бетон', 'unit_price' => '6100.0000', 'unit' => 'м³', 'currency' => null]);
        $rendered = (new AssistantPresentationPlanner)->render($this->pricePlan($receipt), [$receipt], 'Цена за 1м3 бетона');
        self::assertNotNull($rendered);
        self::assertStringContainsString('Валюта: не указано', $rendered);
        self::assertStringNotContainsString('RUB', $rendered);
        self::assertNull((new AssistantPresentationPlanner)->render($this->plan($receipt, ['name', 'unit_price', 'currency']), [$receipt], 'Цена за 1м3 бетона'));
    }

    public function test_native_money_without_a_currency_column_remains_presentable_without_inventing_currency(): void
    {
        foreach (['project' => 'budget_amount', 'contract' => 'total_amount', 'completed_work' => 'total_amount', 'purchase_request' => 'budget_amount'] as $type => $field) {
            $raw = $this->receipt(91, $type, [$field => '123.45']);
            $row = AssistantStructuredFactFormatter::withUnknownCurrency($raw['structured_fact_evidence']['rows'][0]);
            $receipt = AssistantStructuredFactFormatter::payload([$row], '2026-10-03T18:39:00Z');
            $receipt += AssistantDomainNumericEvidence::payload($raw['structured_fact_evidence']['rows'], '2026-10-03T18:39:00Z');
            $plan = $this->plan($receipt, [$field, 'currency']);
            $result = (new AssistantFinancialClaimVerifier)->guard('Сумма 0', [$receipt], $plan);

            self::assertStringContainsString('123.45', $result['text'], $type);
            self::assertStringContainsString('Валюта: не указано', $result['text'], $type);
            self::assertStringNotContainsString('RUB', $result['text'], $type);
            self::assertSame([91], array_column($result['source_refs'], 'entity_id'), $type);
        }
    }

    public function test_multiple_financial_receipts_do_not_choose_an_arbitrary_fallback_without_a_model_selection(): void
    {
        $receipts = [];
        foreach ([91, 92] as $id) {
            $raw = $this->receipt($id, 'contract', ['total_amount' => (string) $id]);
            $receipts[] = AssistantDomainNumericEvidence::payload($raw['structured_fact_evidence']['rows'], '2026-10-03T18:39:00Z');
        }
        $financial = (new AssistantFinancialClaimVerifier)->guard('Сумма 999', $receipts);
        $structured = (new AssistantStructuredFactVerifier)->guard('Какая сумма?', 'Сумма 999', $receipts);

        self::assertSame([], $financial['source_refs']);
        self::assertSame([], $structured['source_refs']);
        self::assertTrue($structured['needs_clarification']);
        self::assertStringNotContainsString('91', $financial['text']);
    }

    private function positions(): array
    {
        $first = $this->receipt(91, 'estimate_item', ['name' => 'Бетон В25', 'unit_price' => '6100.0000', 'unit' => 'м³', 'currency' => 'RUB']);
        $second = $this->receipt(92, 'estimate_item', ['name' => 'Кирпич', 'unit_price' => '12.00', 'unit' => 'шт', 'currency' => 'RUB']);

        return AssistantStructuredFactFormatter::payload(array_merge($first['structured_fact_evidence']['rows'], $second['structured_fact_evidence']['rows']), '2026-10-03T18:39:00Z');
    }

    private function receipt(int $id, string $type, array $fields): array
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => $id] + $fields, true);
        $source = ['entity_type' => $type, 'entity_id' => $id, 'organization_id' => 3, 'content_scope' => 'structured',
            'checked_fields' => array_keys($fields), 'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view'],
            'required_domains' => ['estimates'], 'source_version' => 'current-'.$id, 'fetched_at' => '2026-10-03T18:39:00Z'];

        return AssistantStructuredFactFormatter::payload([AssistantStructuredFactFormatter::row($model, $type, array_keys($fields), $source)], $source['fetched_at']);
    }

    private function pricePlan(array $positions): string
    {
        return $this->plan($positions, ['name', 'unit_price', 'unit', 'currency']);
    }

    private function plan(array $positions, array $columns): string
    {
        return json_encode(['kind' => 'verified_rows', 'version' => 1, 'result_sets' => [[
            'result_set' => AssistantPresentationPlanner::providerView($positions)['result_set'], 'layout' => 'list',
            'columns' => $columns, 'order' => ['r01'], 'group_by' => null,
        ]]], JSON_THROW_ON_ERROR);
    }
}
