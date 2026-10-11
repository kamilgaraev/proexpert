<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimModelSelector;
use PHPUnit\Framework\TestCase;

final class AssistantBimModelSelectorTest extends TestCase
{
    public function test_project_question_does_not_turn_project_name_into_a_file_selector(): void
    {
        self::assertNull(AssistantBimModelSelector::withoutProjectAlias('севгу', 'СевГУ: учебный корпус',
            'у нас в проекте севгу есть кондиционеры, сколько их?'));
        self::assertSame('севгу', AssistantBimModelSelector::namedProject('у нас в проекте севгу есть кондиционеры, сколько их?'));
        self::assertSame('Красный дом', AssistantBimModelSelector::namedProject('В проекте Красный дом сколько кондиционеров?'));
        self::assertNull(AssistantBimModelSelector::namedProject('Сколько кондиционеров в текущем проекте?'));
        self::assertNull(AssistantBimModelSelector::namedProject('В текущем проекте есть кондиционеры, сколько их?'));
        self::assertNull(AssistantBimModelSelector::namedProject('В этом проекте посчитай кондиционеры.'));
        self::assertNull(AssistantBimModelSelector::namedProject('По проекту посчитай кондиционеры.'));
        self::assertSame('Текущий проект стройки', AssistantBimModelSelector::namedProject('В проекте «Текущий проект стройки» сколько кондиционеров?'));
    }

    public function test_explicit_file_model_and_unrelated_selectors_are_preserved(): void
    {
        foreach ([
            ['севгу', 'СевГУ', 'В проекте СевГУ проверь модель «СевГУ».'],
            ['севгу', 'СевГУ', "В проекте СевГУ проверь модель 'СевГУ'."],
            ['севгу', 'СевГУ', 'В проекте СевГУ проверь файл IFC «СевГУ».'],
            ['севгу', 'СевГУ', 'В проекте СевГУ проверь модель «Часть СевГУ».'],
            ['севгу', 'СевГУ', 'В проекте СевГУ проверь файл hvac.ifc.'],
            ['гаражной', 'СевГУ', 'В проекте СевГУ сколько колонн у гаражной?'],
            ['севгу', 'Другой проект', 'В проекте СевГУ сколько кондиционеров?'],
            ['севгу', 'СевГУ', 'Проверь модель СевГУ.'],
        ] as [$selector, $project, $question]) {
            self::assertSame($selector, AssistantBimModelSelector::withoutProjectAlias($selector, $project, $question));
        }
        self::assertNull(AssistantBimModelSelector::withoutProjectAlias(null, 'СевГУ', 'Сколько кондиционеров?'));
    }
}
