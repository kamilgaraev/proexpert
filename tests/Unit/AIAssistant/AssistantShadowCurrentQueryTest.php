<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\ShadowDomainScenario;

final class AssistantShadowCurrentQueryTest extends TestCase
{
    public function test_real_financial_entity_question_remains_at_the_tail_of_exactly_4000_unicode_characters(): void
    {
        $question = 'Покажи точную сумму платежного документа 12345 «МОСТ QA акт», укажи валюту и источник.';
        $query = (new ReflectionMethod(ShadowDomainScenario::class, 'longQuery'))->invoke(null, $question);
        $this->assertSame(4000, mb_strlen($query));
        $this->assertStringEndsWith($question, $query);
        $this->assertStringContainsString('действующие права доступа', $query);
        $this->assertSame($question, mb_substr($query, -mb_strlen($question)));
    }
}
