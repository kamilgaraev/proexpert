<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantPresentationPlanner;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use Illuminate\Database\Eloquent\Model;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use PHPUnit\Framework\TestCase;

final class AssistantPresentationPlannerTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_provider_contract_uses_opaque_set_and_ephemeral_rows_without_ids_or_values(): void
    {
        $receipt = $this->payload(8, 'project', ['name' => 'Северный корпус', 'status' => 'active']);
        $view = AssistantPresentationPlanner::providerView($receipt);

        $this->assertSame('verified_rows', $view['kind']);
        $this->assertSame(1, $view['version']);
        $this->assertMatchesRegularExpression('/^s_[a-f0-9]{24}$/', $view['result_set']);
        $this->assertSame(['name', 'status'], $view['fields']);
        $this->assertArrayNotHasKey('rows', $view);
        $this->assertStringNotContainsString('Северный', json_encode($view, JSON_THROW_ON_ERROR));
    }

    public function test_model_plan_selects_generic_layout_and_order_but_renderer_uses_only_proof_values(): void
    {
        $first = $this->payload(8, 'project', ['name' => '[Вредная](https://evil.test)', 'status' => 'active']);
        $second = $this->payload(9, 'project', ['name' => 'Западный корпус', 'status' => 'draft']);
        $combined = AssistantStructuredFactFormatter::payload(array_merge(
            $first['structured_fact_evidence']['rows'], $second['structured_fact_evidence']['rows']), '2026-10-01T10:00:00Z');
        $view = AssistantPresentationPlanner::providerView($combined);
        $plan = json_encode(['kind' => 'verified_rows', 'version' => 1, 'result_sets' => [[
            'result_set' => $view['result_set'], 'layout' => 'table', 'columns' => ['name', 'status'],
            'order' => ['r02', 'r01'], 'group_by' => null,
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $result = (new AssistantStructuredFactVerifier)->guard('Какой статус у объектов?', $plan, [$combined])['text'];
        $html = (string) (new GithubFlavoredMarkdownConverter)->convert($result);

        $this->assertStringContainsString('| Запись | Название | Статус |', $result);
        $this->assertLessThan(strpos($result, 'Вредная'), strpos($result, 'Западный корпус'));
        $this->assertStringNotContainsString('https://evil.test', $result);
        $this->assertStringNotContainsString('href="https://evil.test', $html);
        $this->assertStringNotContainsString('entity_id', $result);
    }

    public function test_money_and_quantity_require_selected_verified_currency_and_unit_with_exact_decimals(): void
    {
        $money = $this->payload(11, 'payment_document', ['amount' => '9007199254740993.17', 'currency' => 'RUB']);
        $moneyPlan = $this->plan([$money], [['amount', 'currency']], 'list');
        $validMoney = (new AssistantStructuredFactVerifier)->guard('Какая сумма оплаты?', $moneyPlan, [$money]);

        $this->assertStringContainsString('9007199254740993.17', $validMoney['text']);
        $this->assertStringContainsString('RUB', $validMoney['text']);
        $this->assertSame('partial', $validMoney['validation_status']);

        $missingCurrency = $this->renderPlan([$money], [['amount']], 'list');
        $this->assertStringNotContainsString('9007199254740993.17', $missingCurrency);

        $quantity = $this->payload(12, 'purchase_order', ['material_quantity' => '4.000', 'material_unit' => 'м³']);
        $validQuantityPlan = $this->plan([$quantity], [['material_quantity', 'material_unit']], 'list');
        $validQuantity = (new AssistantPresentationPlanner)->render($validQuantityPlan, [$quantity], 'Количество поставки');
        $this->assertStringContainsString('4.000', $validQuantity);
        $this->assertStringContainsString('м³', $validQuantity);
        $this->assertStringNotContainsString('12', $validQuantity);
    }

    public function test_money_selection_requires_current_financial_receipt_for_every_selected_row_and_org(): void
    {
        $money = $this->payload(31, 'payment_document', ['amount' => '10.25', 'currency' => 'RUB']);
        $plan = $this->plan([$money], [['amount', 'currency']], 'list');
        $source = $money['structured_fact_evidence']['rows'][0]['source_ref'];
        $planner = new AssistantPresentationPlanner;

        $this->assertTrue($planner->financialSelectionCovered($plan, [$money], [$source]));
        $this->assertFalse($planner->financialSelectionCovered($plan, [$money], []));
        $otherOrganization = $source;
        $otherOrganization['organization_id'] = 99;
        $this->assertFalse($planner->financialSelectionCovered($plan, [$money], [$otherOrganization]));
        $otherEntity = $source;
        $otherEntity['entity_id'] = 999;
        $this->assertFalse($planner->financialSelectionCovered($plan, [$money], [$otherEntity]));
    }

    public function test_rejects_extra_keys_fake_or_repeated_refs_and_unchecked_required_fields(): void
    {
        $receipt = $this->payload(20, 'project', ['name' => 'Объект', 'status' => 'active']);
        $base = $this->planObject([$receipt], [['name', 'status']], 'list');

        $extra = $base;
        $extra['free_text'] = 'Все готово';
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($extra), [$receipt], 'Какой статус?'));

        $fake = $base;
        $fake['result_sets'][0]['order'] = ['r99'];
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($fake), [$receipt], 'Какой статус?'));

        $duplicate = $base;
        $duplicate['result_sets'][0]['order'] = ['r01', 'r01'];
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($duplicate), [$receipt], 'Какой статус?'));

        $missing = $base;
        $missing['result_sets'][0]['order'] = [];
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($missing), [$receipt], 'Какой статус?'));

        $unchecked = $base;
        $unchecked['result_sets'][0]['columns'] = ['name', 'owner_user_id'];
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($unchecked), [$receipt], 'Какой статус?'));

        $missingRequired = $this->planObject([$receipt], [['name']], 'list');
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($missingRequired), [$receipt], 'Какой статус?'));
    }

    public function test_rejects_unselected_group_key_and_noncontiguous_group_order(): void
    {
        $rows = [$this->payload(1, 'project', ['name' => 'А', 'status' => 'active']),
            $this->payload(2, 'project', ['name' => 'Б', 'status' => 'draft']),
            $this->payload(3, 'project', ['name' => 'В', 'status' => 'active'])];
        $combined = AssistantStructuredFactFormatter::payload(array_merge(...array_map(
            static fn (array $receipt): array => $receipt['structured_fact_evidence']['rows'], $rows)), '2026-10-01T10:00:00Z');
        $plan = $this->planObject([$combined], [['status', 'name']], 'list');
        $plan['result_sets'][0]['group_by'] = 'status';
        $plan['result_sets'][0]['order'] = ['r01', 'r02', 'r03'];

        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($plan), [$combined], 'Покажи объекты'));
        $plan['result_sets'][0]['columns'] = ['name'];
        $this->assertNull((new AssistantPresentationPlanner)->render(json_encode($plan), [$combined], 'Покажи объекты'));
    }

    public function test_grouped_tables_render_separate_markdown_blocks_with_extended_field_labels(): void
    {
        $receipts = [
            $this->payload(41, 'project', ['name' => 'А', 'unit' => 'м³']),
            $this->payload(42, 'project', ['name' => 'Б', 'unit' => 'м³']),
            $this->payload(43, 'project', ['name' => 'В', 'unit' => 'шт']),
        ];
        $combined = AssistantStructuredFactFormatter::payload(array_merge(...array_map(
            static fn (array $receipt): array => $receipt['structured_fact_evidence']['rows'], $receipts)), '2026-10-01T10:00:00Z');
        $plan = $this->planObject([$combined], [['name', 'unit']], 'table');
        $plan['result_sets'][0]['group_by'] = 'unit';
        $rendered = (new AssistantPresentationPlanner)->render(json_encode($plan, JSON_THROW_ON_ERROR), [$combined], 'Покажи данные');

        $this->assertNotNull($rendered);
        $this->assertSame(2, substr_count($rendered, '| Запись | Название | Единица |'));
        $this->assertStringContainsString("Единица: м³\n\n| Запись | Название | Единица |", $rendered);
        $this->assertStringContainsString("Единица: шт\n\n| Запись | Название | Единица |", $rendered);
        $html = (string) (new GithubFlavoredMarkdownConverter)->convert($rendered);
        $this->assertSame(2, substr_count($html, '<table>'));

        $mixedPlan = $this->planObject([$receipts[0], $receipts[2]], [['name', 'unit'], ['name', 'unit']], 'table');
        $mixedPlan['result_sets'][1]['layout'] = 'list';
        $mixed = (new AssistantPresentationPlanner)->render(json_encode($mixedPlan, JSON_THROW_ON_ERROR), [$receipts[0], $receipts[2]], 'Покажи данные');
        $this->assertStringContainsString("| Запись | Название | Единица |\n| --- | --- | --- |\n| Проект | А | м³ |\n\nПроект:", $mixed);
    }

    private function renderPlan(array $receipts, array $columnsByReceipt, string $layout): string
    {
        return (new AssistantStructuredFactVerifier)->guard('Какой статус у этих записей?', $this->plan($receipts, $columnsByReceipt, $layout), $receipts)['text'];
    }

    private function plan(array $receipts, array $columnsByReceipt, string $layout): string
    {
        return json_encode($this->planObject($receipts, $columnsByReceipt, $layout), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function planObject(array $receipts, array $columnsByReceipt, string $layout): array
    {
        $sets = [];
        foreach ($receipts as $index => $receipt) {
            $view = AssistantPresentationPlanner::providerView($receipt);
            $sets[] = ['result_set' => $view['result_set'], 'layout' => $layout,
                'columns' => $columnsByReceipt[$index] ?? $columnsByReceipt[0],
                'order' => array_map(static fn (int $row): string => 'r'.str_pad((string) ($row + 1), 2, '0', STR_PAD_LEFT),
                    array_keys($receipt['structured_fact_evidence']['rows'])), 'group_by' => null];
        }

        return ['kind' => 'verified_rows', 'version' => 1, 'result_sets' => $sets];
    }

    private function payload(int $id, string $type, array $values): array
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => $id] + $values, true);
        $fields = array_keys($values);
        $reference = ['organization_id' => 3, 'entity_type' => $type, 'entity_id' => $id, 'content_scope' => 'structured',
            'checked_fields' => $fields, 'required_permissions' => ['project.view'], 'required_domains' => ['project'],
            'source_version' => 'row-'.$id, 'fetched_at' => '2026-10-01T10:00:00Z'];
        $row = AssistantStructuredFactFormatter::row($model, $type, $fields, $reference);

        return AssistantStructuredFactFormatter::payload([$row], '2026-10-01T10:00:00Z');
    }
}
