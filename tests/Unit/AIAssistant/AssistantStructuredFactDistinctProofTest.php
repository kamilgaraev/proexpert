<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class AssistantStructuredFactDistinctProofTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_distinct_fields_of_the_same_entity_and_version_survive_while_identical_receipts_deduplicate(): void
    {
        $amount = $this->payload(7, ['amount' => '9007199254740993.17']);
        $name = $this->payload(7, ['name' => 'Платёж А']);
        $status = $this->payload(7, ['status' => 'paid']);
        $result = (new AssistantStructuredFactVerifier)->guard('Какой статус и сумма?', 'Непроверенный ответ', [$amount, $name, $status, $amount]);
        $this->assertFalse($result['needs_clarification']);
        $this->assertFalse($result['structured_evidence_truncated']);
        $this->assertCount(3, $result['source_refs']);
        $this->assertStringContainsString('9007199254740993.17', $result['text']);
        $this->assertStringContainsString('Платёж А', $result['text']);
        $this->assertStringContainsString('Статус:', $result['text']);
    }

    public function test_overflow_discloses_partial_evidence_and_never_answers_from_a_dropped_amount(): void
    {
        $results = [];
        for ($id = 1; $id <= 25; $id++) { $results[] = $this->payload($id, ['status' => 'paid']); }
        $results[] = $this->payload(26, ['amount' => '123.45']);
        $result = (new AssistantStructuredFactVerifier)->guard('Какой статус и сумма?', 'Сумма 123.45', $results);
        $this->assertTrue($result['needs_clarification']);
        $this->assertTrue($result['structured_evidence_truncated']);
        $this->assertSame('partial', $result['validation_status']);
        $this->assertSame([], $result['source_refs']);
    }

    private function payload(int $id, array $fields): array
    {
        $model = new class extends Model {};
        $model->setRawAttributes(['id' => $id, ...$fields], true);
        $reference = ['entity_type' => 'payment_document', 'entity_id' => $id, 'organization_id' => 1,
            'content_scope' => 'structured', 'source_version' => 'same-updated-at', 'checked_fields' => array_keys($fields),
            'required_permissions' => isset($fields['amount']) ? ['finance.view'] : [], 'required_domains' => ['finance'],
            'fetched_at' => '2026-09-29T12:00:00Z'];
        return AssistantStructuredFactFormatter::payload([AssistantStructuredFactFormatter::row($model, 'payment_document', array_keys($fields), $reference)], $reference['fetched_at']);
    }
}
