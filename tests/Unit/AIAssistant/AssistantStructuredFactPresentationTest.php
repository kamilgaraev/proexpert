<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantStructuredFactPresentationTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_default_presentation_shows_business_identity_and_status_across_domains_without_technical_fields(): void
    {
        foreach ([
            ['material', ['name' => 'Песок промытый', 'code' => 'М-17', 'unit_price' => '999.99', 'measurement_unit_id' => 23], 'Песок промытый'],
            ['project', ['name' => 'Северный корпус', 'status' => 'active', 'end_date' => '2026-12-31', 'contract_id' => 24], 'Северный корпус'],
            ['purchase_order', ['number' => 'ЗП-81', 'status' => 'draft', 'project_id' => 25, 'delivery_date' => null], 'ЗП\\-81'],
        ] as [$type, $fields, $title]) {
            $payload = $this->payload($type, ['id' => 321, 'created_at' => '2026-09-01', ...$fields]);
            $before = $payload['structured_fact_evidence'];
            $result = (new AssistantStructuredFactVerifier)->confirmedResults([$payload], 'Что у нас по этим записям?');

            $this->assertStringContainsString($title, $result['text']);
            $this->assertStringContainsString('[Открыть запись](/records?entity_id=321)', $result['text']);
            $this->assertStringNotContainsString('Идентификатор', $result['text']);
            $this->assertStringNotContainsString('№321', $result['text']);
            $this->assertStringNotContainsString('2026', $result['text']);
            $this->assertStringNotContainsString('999.99', $result['text']);
            $this->assertStringNotContainsString('не указано', $result['text']);
            $this->assertStringNotContainsString('Данные прочитаны', $result['text']);
            $this->assertSame($before, $payload['structured_fact_evidence']);
            $this->assertSame($before['source_refs'], $result['source_refs']);
        }
    }

    public function test_requested_prices_dates_and_business_details_are_preserved_from_proof(): void
    {
        $payload = $this->payload('purchase_order', ['number' => 'ЗП-18', 'status' => 'draft', 'unit_price' => '9007199254740993.17',
            'delivery_date' => '2026-12-31', 'currency' => 'RUB', 'project_id' => 812, 'address' => 'Склад на Садовой']);
        $result = (new AssistantStructuredFactVerifier)->guard('Подробно покажи заказ, цену и дату доставки.', 'Цена 0, доставка завтра.', [$payload]);

        $this->assertFalse($result['needs_clarification']);
        $this->assertStringContainsString('9007199254740993.17', $result['text']);
        $this->assertStringContainsString('2026\\-12\\-31', $result['text']);
        $this->assertStringContainsString('Склад на Садовой', $result['text']);
        $this->assertStringNotContainsString('812', $result['text']);
        $this->assertStringNotContainsString('завтра', $result['text']);
        $this->assertSame($payload['structured_fact_evidence']['source_refs'], $result['source_refs']);
    }

    public function test_exact_quantities_units_and_financial_values_remain_without_model_aggregation(): void
    {
        $delivery = $this->payload('purchase_order', ['number' => 'ЗП-19', 'material_name' => 'Щебень', 'material_quantity' => '4.000', 'material_unit' => 'м³']);
        $payment = $this->payload('payment_document', ['number' => 'СЧ-20', 'amount' => '9007199254740993.17', 'currency' => 'RUB']);
        $result = (new AssistantStructuredFactVerifier)->confirmedResults([$delivery, $payment], 'Поставки и оплаты');

        $this->assertStringContainsString('4.000', $result['text']);
        $this->assertStringContainsString('м³', $result['text']);
        $this->assertStringContainsString('9007199254740993.17', $result['text']);
        $this->assertCount(2, $result['source_refs']);
        $this->assertSame('partial', $result['validation_status']);
        $this->assertStringContainsString('Полнота списка и общие итоги не подтверждены', $result['text']);
    }

    public function test_natural_payment_query_renders_checked_rows_as_a_table_without_inventing_currency(): void
    {
        $receipts = [];
        for ($id = 1; $id <= 7; $id++) {
            $receipts[] = $this->payload('payment_document', [
                'document_number' => 'ПЛ-'.$id,
                'amount' => '100.0'.$id,
            ], 320 + $id);
        }
        $combined = AssistantStructuredFactFormatter::payload(array_merge(...array_map(
            static fn (array $receipt): array => $receipt['structured_fact_evidence']['rows'], $receipts)), '2026-09-30T12:00:00Z');
        $text = (new AssistantStructuredFactVerifier)->guard('Что с платежами?', 'Найдено семь платежей: ...', [$combined])['text'];

        $this->assertStringContainsString('| Запись | Номер документа | Сумма |', $text);
        $this->assertStringContainsString('| ПЛ\-1 | 100.01 |', $text);
        $this->assertStringContainsString('| ПЛ\-7 | 100.07 |', $text);
        $this->assertStringNotContainsString('RUB', $text);
        $this->assertStringNotContainsString('не указано', $text);
    }

    public function test_payment_currency_is_rendered_only_for_rows_with_verified_nonempty_currency(): void
    {
        $first = $this->payload('payment_document', ['document_number' => 'ПЛ-1', 'amount' => '12.50', 'currency' => 'EUR'], 401);
        $second = $this->payload('payment_document', ['document_number' => 'ПЛ-2', 'amount' => '7.25', 'currency' => null], 402);
        $combined = AssistantStructuredFactFormatter::payload(array_merge(
            $first['structured_fact_evidence']['rows'], $second['structured_fact_evidence']['rows']), '2026-09-30T12:00:00Z');
        $text = (new AssistantStructuredFactVerifier)->confirmedResults([$combined], 'Что с платежами?')['text'];

        $this->assertStringContainsString('EUR', $text);
        $this->assertStringNotContainsString('RUB', $text);
        $this->assertStringNotContainsString('Валюта: не указано', $text);
    }

    public function test_explanatory_payment_question_keeps_narrative_response(): void
    {
        $receipt = $this->payload('payment_document', ['document_number' => 'ПЛ-1', 'amount' => '12.50'], 403);
        $answer = 'Объясню общий порядок обработки платежей.';

        $result = (new AssistantStructuredFactVerifier)->guard('Почему платежи задерживаются?', $answer, [$receipt]);

        $this->assertSame($answer, $result['text']);
        $this->assertSame([], $result['source_refs']);
        $this->assertFalse($result['needs_clarification']);
    }

    public function test_unrelated_question_keeps_original_response_even_with_payment_evidence(): void
    {
        $receipt = $this->payload('payment_document', ['document_number' => 'ПЛ-1', 'amount' => '12.50'], 404);
        $answer = 'Сегодня хорошая погода.';

        $result = (new AssistantStructuredFactVerifier)->guard('Расскажи анекдот.', $answer, [$receipt]);

        $this->assertSame($answer, $result['text']);
        $this->assertSame([], $result['source_refs']);
        $this->assertFalse($result['replaced']);
        $this->assertFalse($result['needs_clarification']);
    }

    public function test_composition_page_metadata_adds_partial_footer_from_verified_resource_rows(): void
    {
        $rows = $this->compositionRows(20);
        $payload = AssistantStructuredFactFormatter::payload($rows, '2026-09-30T12:00:00Z');
        $payload['structured_fact_evidence']['composition_page'] = [
            'scope' => 'resources', 'position_id' => 200, 'total' => 25, 'page' => 1,
            'per_page' => 20, 'has_more' => true, 'next_page' => 2,
        ];

        $result = (new AssistantStructuredFactVerifier)->confirmedResults([$payload], 'Покажи состав позиции');

        $this->assertStringContainsString('Показаны 20 из 25 найденных ресурсов.', $result['text']);
        $this->assertSame(22, count($result['source_refs']));
        $this->assertFalse($result['needs_clarification']);
    }

    public function test_conflicting_composition_pages_use_unknown_resources_footer(): void
    {
        $firstPage = AssistantStructuredFactFormatter::payload($this->compositionRows(20), '2026-09-30T12:00:00Z');
        $firstPage['structured_fact_evidence']['composition_page'] = [
            'scope' => 'resources', 'position_id' => 200, 'total' => 25, 'page' => 1,
            'per_page' => 20, 'has_more' => true, 'next_page' => 2,
        ];
        $secondPage = AssistantStructuredFactFormatter::payload($this->compositionRows(6, 21), '2026-09-30T12:00:00Z');
        $secondPage['structured_fact_evidence']['composition_page'] = [
            'scope' => 'resources', 'position_id' => 200, 'total' => 26, 'page' => 2,
            'per_page' => 20, 'has_more' => false, 'next_page' => null,
        ];
        $verifier = new AssistantStructuredFactVerifier;
        $this->assertTrue($verifier->trustedEvidence($firstPage['structured_fact_evidence']));
        $this->assertTrue($verifier->trustedEvidence($secondPage['structured_fact_evidence']));

        $result = $verifier->confirmedResults([$firstPage, $secondPage], 'Покажи состав позиции');

        $this->assertStringContainsString('Показана часть найденных ресурсов.', $result['text']);
        $this->assertStringNotContainsString('найденных позиций', $result['text']);
        $this->assertFalse($result['needs_clarification']);
    }

    public function test_inconsistent_composition_page_metadata_is_not_trusted(): void
    {
        $payload = AssistantStructuredFactFormatter::payload($this->compositionRows(20), '2026-09-30T12:00:00Z');
        $payload['structured_fact_evidence']['composition_page'] = [
            'scope' => 'resources', 'position_id' => 200, 'total' => 25, 'page' => 1,
            'per_page' => 20, 'has_more' => false, 'next_page' => 2,
        ];

        $result = (new AssistantStructuredFactVerifier)->confirmedResults([$payload], 'Покажи состав позиции');

        $this->assertSame([], $result['source_refs']);
        $this->assertStringNotContainsString('Показаны 20 из 25 найденных ресурсов.', $result['text']);
        $this->assertTrue($result['needs_clarification']);
    }

    public function test_explicit_missing_date_and_owner_proof_remain_meaningful_without_invented_names_or_dates(): void
    {
        $payload = $this->payload('crm_deal', ['name' => 'Поставка утеплителя', 'status' => 'draft', 'owner_user_id' => 42, 'expected_close_at' => null]);
        $result = (new AssistantStructuredFactVerifier)->guard('Кто ответственный за сделку и когда плановое закрытие?', 'Антон, завтра.', [$payload]);

        $this->assertFalse($result['needs_clarification']);
        $this->assertStringContainsString('Идентификатор ответственного: 42', $result['text']);
        $this->assertStringContainsString('Плановое закрытие: не указано', $result['text']);
        $this->assertStringNotContainsString('Антон', $result['text']);
        $this->assertStringNotContainsString('завтра', $result['text']);
    }

    private function payload(string $type, array $fields, int $id = 321, array $sourceExtras = []): array
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => $id, ...$fields], true);
        $reference = ['organization_id' => 15, 'entity_type' => $type, 'entity_id' => $id, 'content_scope' => 'structured',
            'checked_fields' => array_keys($fields), 'source_version' => 'current-version', 'fetched_at' => '2026-09-30T12:00:00Z',
            'navigation' => ['url' => '/records?entity_id='.$id], ...$sourceExtras];

        return AssistantStructuredFactFormatter::payload([AssistantStructuredFactFormatter::row($model, $type, array_keys($fields), $reference)], $reference['fetched_at']);
    }

    private function compositionRows(int $resourceCount, int $firstResourceId = 1): array
    {
        $estimate = $this->payload('estimate', ['name' => 'Смета'], 100);
        $position = $this->payload('estimate_item', ['name' => 'Позиция'], 200, ['estimate_id' => 100]);
        $rows = array_merge($estimate['structured_fact_evidence']['rows'], $position['structured_fact_evidence']['rows']);
        for ($id = $firstResourceId; $id < $firstResourceId + $resourceCount; $id++) {
            $resource = $this->payload('estimate_item_resource', ['name' => 'Ресурс '.$id], 1000 + $id,
                ['estimate_id' => 100, 'estimate_item_id' => 200]);
            $rows[] = $resource['structured_fact_evidence']['rows'][0];
        }

        return $rows;
    }
}
