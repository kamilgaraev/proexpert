<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimAnswerIntent;
use PHPUnit\Framework\TestCase;

final class AssistantBimAnswerIntentTest extends TestCase
{
    public function test_material_list_intent_preserves_extra_scope_and_excludes_quantity_and_compound_requests(): void
    {
        self::assertTrue(AssistantBimAnswerIntent::materialList('Какие материалы встречаются в модели Севгу?'));
        self::assertTrue(AssistantBimAnswerIntent::materialList('Какие материалы встречаются в модели Севгу на третьем этаже?'));
        foreach (['Сколько бетона в модели Севгу?', 'Какие материалы и какие цены в модели Севгу?', 'Какие материалы на складе?'] as $query) {
            self::assertFalse(AssistantBimAnswerIntent::materialList($query));
        }
    }
}
