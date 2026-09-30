<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;
use PHPUnit\Framework\TestCase;

final class AssistantFactIntentClassifierTest extends TestCase
{
    public function test_financial_record_questions_are_factual_but_explanatory_questions_stay_narrative(): void
    {
        $this->assertTrue(AssistantFactIntentClassifier::isFactual('Что с платежами?'));
        $this->assertTrue(AssistantFactIntentClassifier::isFactual('Что со счетами?'));
        $this->assertTrue(AssistantFactIntentClassifier::isFactual('Какой статус платежа?'));

        $this->assertTrue(AssistantFactIntentClassifier::isNarrative('Как проходят платежи?'));
        $this->assertFalse(AssistantFactIntentClassifier::isFactual('Как проходят платежи?'));
        $this->assertTrue(AssistantFactIntentClassifier::isNarrative('Почему платежи задерживаются?'));
        $this->assertFalse(AssistantFactIntentClassifier::isFactual('Почему платежи задерживаются?'));
    }
}
