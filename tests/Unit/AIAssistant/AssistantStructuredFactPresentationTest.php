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

    private function payload(string $type, array $fields): array
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => 321, ...$fields], true);
        $reference = ['organization_id' => 15, 'entity_type' => $type, 'entity_id' => 321, 'content_scope' => 'structured',
            'checked_fields' => array_keys($fields), 'source_version' => 'current-version', 'fetched_at' => '2026-09-30T12:00:00Z',
            'navigation' => ['url' => '/records?entity_id=321']];

        return AssistantStructuredFactFormatter::payload([AssistantStructuredFactFormatter::row($model, $type, array_keys($fields), $reference)], $reference['fetched_at']);
    }
}
