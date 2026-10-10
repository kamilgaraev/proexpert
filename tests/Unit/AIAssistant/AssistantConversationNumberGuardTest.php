<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialClaimVerifier;
use PHPUnit\Framework\TestCase;

final class AssistantConversationNumberGuardTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_conversation_numbers_are_not_live_business_evidence(): void
    {
        $verifier = new AssistantFinancialClaimVerifier;
        foreach ([
            'Какое условное число я назвал в предыдущем сообщении?',
            'Реши учебный арифметический пример: 12 + 8 + 5.',
        ] as $query) {
            self::assertTrue(AssistantFactIntentClassifier::isNarrative($query));
            self::assertFalse(AssistantFactIntentClassifier::isFactual($query));
            $result = $verifier->guard('Условный объём: 12 м³.', [], null, $query);
            self::assertFalse($result['replaced']);
            self::assertSame('unverified', $result['validation_status']);
            self::assertSame([], $result['source_refs']);
            self::assertFalse($verifier->guard('Сумма чисел: 25.', [], null, $query)['replaced']);
            self::assertTrue($verifier->guard('Цена 100 руб.', [], null, $query)['replaced']);
        }
    }

    public function test_business_quantities_and_mixed_financial_requests_still_require_live_proof(): void
    {
        $verifier = new AssistantFinancialClaimVerifier;
        foreach ([
            'Какой объём бетона на складе?',
            'Реши учебный пример и покажи фактический объём на складе.',
            'Какое условное число я назвал и какова стоимость в смете?',
            'Какое условное число я назвал в предыдущем сообщении и какой статус проекта?',
        ] as $query) {
            self::assertFalse(AssistantFactIntentClassifier::isConversationNumberRequest($query));
            self::assertTrue($verifier->guard('Объём бетона: 12 м³.', [], null, $query)['replaced']);
            self::assertTrue($verifier->guard('Цена 100 руб.', [], null, $query)['replaced']);
        }
    }
}
