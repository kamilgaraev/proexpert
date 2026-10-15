<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use PHPUnit\Framework\TestCase;

final class AssistantMutationIntentTest extends TestCase
{
    public function test_make_summary_is_read_only_without_hiding_a_second_mutation(): void
    {
        foreach (['Сделай сводку по проектам', 'Пожалуйста, сделайте краткую сводку по проектам',
            'Нужно сделать сводку по проектам', 'Сделай обзор по проектам',
            'Сделай список договоров проекта', 'Сделай подборку просроченных задач',
            'Сделай таблицу договоров проекта', 'Сделай перечень договоров', 'Сделай выборку просроченных задач',
            'Примени фильтр по активным договорам', 'Примените сортировку по сроку окончания',
            'Поставь фильтр по активным договорам', 'Выставь сортировку по сроку окончания',
            'Задай фильтр по активным договорам', 'Установи фильтр по активным договорам',
            'Настрой сортировку по сроку окончания'] as $query) {
            self::assertNull(AssistantMutationIntent::type($query), $query);
            self::assertFalse(AssistantMutationIntent::containsRequest($query), $query);
        }
        foreach (['Сделай сводку по проектам и поставь лимит 10000',
            'Сделай сводку и сделай лимит подотчётных средств 10000'] as $query) {
            self::assertTrue(AssistantMutationIntent::containsRequest($query), $query);
        }
    }

    public function test_setting_changes_are_mutations_in_both_command_and_contains_checks(): void
    {
        foreach (['Увеличь', 'Увеличьте', 'Уменьши', 'Уменьшите', 'Настрой', 'Настройте', 'Установи', 'Установите',
            'Снизь', 'Снизьте', 'Повысь', 'Повысьте', 'Поменяй', 'Поменяйте', 'Задай', 'Задайте', 'Выставь', 'Выставьте',
            'Сделай', 'Сделайте', 'Поставь', 'Поставьте', 'Подправь', 'Подправьте', 'Скорректируй', 'Скорректируйте',
            'Отрегулируй', 'Отрегулируйте', 'Подними', 'Поднимите', 'Понизь', 'Понизьте', 'Сократи', 'Сократите',
            'Продли', 'Продлите', 'Сбрось', 'Сбросьте', 'Обнули', 'Обнулите', 'Примени', 'Примените'] as $command) {
            self::assertSame('update', AssistantMutationIntent::type($command.' лимит подотчётных средств'));
            self::assertTrue(AssistantMutationIntent::containsRequest('Покажи настройки и '.$command.' лимит подотчётных средств'));
        }
        foreach (['Увеличить', 'Уменьшить', 'Настроить', 'Установить', 'Снизить', 'Повысить', 'Поменять', 'Задать', 'Выставить',
            'Сделать', 'Поставить', 'Подправить', 'Скорректировать', 'Отрегулировать', 'Поднять', 'Понизить', 'Сократить', 'Продлить', 'Сбросить', 'Обнулить', 'Применить'] as $command) {
            self::assertTrue(AssistantMutationIntent::containsRequest($command.' лимит подотчётных средств'));
        }
        foreach (['Какие настройки подотчётных средств действуют?', 'Какой лимит установлен для подотчётных средств?',
            'Покажи правила согласования подотчётных средств. Ничего не меняй.'] as $query) {
            self::assertFalse(AssistantMutationIntent::containsRequest($query));
        }
    }
}
