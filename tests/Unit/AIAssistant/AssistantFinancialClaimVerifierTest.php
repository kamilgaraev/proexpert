<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use PHPUnit\Framework\TestCase;

final class AssistantFinancialClaimVerifierTest extends TestCase
{
    public function test_claims_require_current_estimate_position_version_and_exact_decimal(): void
    {
        $evidence = ['estimate' => ['id' => 7], 'version' => 'live-v2', 'fetched_at' => '2026-09-29T12:00:00Z',
            'source_refs' => [['entity_type' => 'estimate', 'entity_id' => 7]], 'validation_status' => 'verified',
            'totals' => ['total_amount' => '9007199254740993.17'],
            'positions' => [['id' => 91, 'quantity' => '0.12345678', 'total_amount' => '9007199254740993.17']]];
        $base = ['estimate_id' => 7, 'evidence_version' => 'live-v2', 'position_id' => 91, 'field' => 'quantity', 'value' => '0.12345678'];
        $verifier = new AssistantFinancialClaimVerifier;
        self::assertSame('verified', $verifier->verifyClaims([$base], $evidence)['validation_status']);
        $invalid = [array_replace($base, ['estimate_id' => 8]), array_replace($base, ['position_id' => 92]),
            array_replace($base, ['evidence_version' => 'old-v1']), array_replace($base, ['value' => '0.12345679']),
            array_replace($base, ['value' => 0.12345678])];
        self::assertCount(5, $verifier->verifyClaims($invalid, $evidence)['rejected_claims']);
        self::assertSame('verified', $verifier->verifyClaims([array_replace($base, ['field' => 'total_amount', 'value' => '9007199254740993.170'])], $evidence)['validation_status']);
        self::assertSame('unverified', $verifier->verifyClaims([$base], array_diff_key($evidence, ['fetched_at' => true]))['validation_status']);
    }

    public function test_past_model_text_is_replaced_by_current_server_answer(): void
    {
        $evidence = ['fetched_at' => 'now', 'source_refs' => [['entity_id' => 7]], 'validation_status' => 'verified'];
        $result = (new AssistantFinancialClaimVerifier)->verifyText('Сумма 1 млн рублей', $evidence, 'Сумма 123.45 руб.');
        self::assertTrue($result['replaced']);
        self::assertSame('unverified', $result['validation_status']);
        self::assertSame('Сумма 123.45 руб.', $result['text']);
    }

    public function test_tool_live_answer_replaces_permuted_model_numbers(): void
    {
        $evidence = ['fetched_at' => 'now', 'source_refs' => [['entity_id' => 7]], 'validation_status' => 'verified'];
        $result = (new AssistantFinancialClaimVerifier)->guard('Позиция 2: 100 руб.; позиция 1: 200 руб.', [
            ['financial_evidence' => $evidence, 'server_formatted_answer' => 'Позиция 1: 100 руб.; позиция 2: 200 руб.'],
        ]);
        self::assertTrue($result['replaced']);
        self::assertSame('verified', $result['validation_status']);
        self::assertSame('Позиция 1: 100 руб.; позиция 2: 200 руб.', $result['text']);
    }
}
