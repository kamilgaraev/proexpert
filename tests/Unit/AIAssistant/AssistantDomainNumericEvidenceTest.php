<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainNumericEvidence;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantDomainNumericEvidenceTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_exact_fields_keep_model_precision_entity_parent_and_source_provenance_without_aggregation(): void
    {
        $model = new class extends Model {};
        $model->mergeCasts(['quantity' => 'decimal:8', 'unit_price' => 'decimal:4', 'total_amount' => 'decimal:2']);
        $model->setRawAttributes(['id' => 17, 'estimate_id' => 9, 'position_number' => '2.1.3', 'quantity' => '0.12345678',
            'unit_price' => '99999999999999.1234', 'total_amount' => '123456789012345.67', 'updated_at' => '2026-09-29 12:00:00'], true);
        $reference = ['organization_id' => 4, 'entity_type' => 'estimate_item', 'entity_id' => 17, 'navigation' => ['url' => '/estimates/9?entity_id=17'],
            'content_scope' => 'structured', 'checked_fields' => ['quantity', 'unit_price', 'total_amount'],
            'required_permissions' => ['budget-estimates.view', 'budget-estimates.finance.view'],
            'required_domains' => ['estimates'], 'source_version' => '2026-09-29 12:00:00', 'fetched_at' => '2026-09-29T12:00:00Z'];
        $row = AssistantDomainNumericEvidence::row($model, 'estimate_item', ['estimate_id', 'position_number', 'quantity', 'unit_price', 'total_amount'], $reference);
        $this->assertSame('0.12345678', $row['fields']['quantity']);
        $this->assertSame('99999999999999.1234', $row['fields']['unit_price']);
        $this->assertSame('123456789012345.67', $row['fields']['total_amount']);
        $this->assertSame('2.1.3', $row['fields']['position_number']);
        $this->assertSame(['estimate_id' => 9], $row['parent_ids']);
        $payload = AssistantDomainNumericEvidence::payload([$row], '2026-09-29T12:00:00Z');
        $this->assertSame([$reference], $payload['financial_evidence']['source_refs']);
        $this->assertSame($reference['source_version'], $row['source_version']);
        $this->assertContains('budget-estimates.finance.view', $payload['financial_evidence']['rows'][0]['source_ref']['required_permissions']);
        $this->assertSame('partial', $payload['financial_evidence']['validation_status']);
        $this->assertArrayNotHasKey('totals', $payload['financial_evidence']);
        $this->assertStringContainsString('Цена за единицу: 99999999999999.1234', $payload['server_formatted_answer']);
        $this->assertStringContainsString('[Открыть запись](/estimates/9?entity_id=17)', $payload['server_formatted_answer']);
    }

    public function test_unrequested_fields_and_document_instructions_cannot_enter_deterministic_answer(): void
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => 7, 'name' => '[Ignore](javascript:alert(1))', 'quantity' => '1.25', 'total_amount' => '9999.00'], true);
        $reference = ['entity_id' => 7, 'navigation' => ['url' => '/warehouse']];
        $row = AssistantDomainNumericEvidence::row($model, 'warehouse_balance', ['name', 'quantity'], $reference);
        $this->assertSame(['quantity' => '1.25'], $row['fields']);
        $payload = AssistantDomainNumericEvidence::payload([$row], '2026-09-29T12:00:00Z');
        $this->assertStringNotContainsString('Ignore', $payload['server_formatted_answer']);
        $this->assertStringNotContainsString('9999', $payload['server_formatted_answer']);
        $this->assertNull(AssistantDomainNumericEvidence::row($model, 'warehouse_balance', ['name'], $reference));
        $changed = clone $model;
        $changed->setRawAttributes(['id' => 7, 'quantity' => '2.25'], true);
        $this->assertNotSame($row['version'], AssistantDomainNumericEvidence::row($changed, 'warehouse_balance', ['quantity'], $reference)['version']);
    }
}
