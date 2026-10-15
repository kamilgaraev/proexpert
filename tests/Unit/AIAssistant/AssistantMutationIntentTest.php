<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantMutationIntent;
use PHPUnit\Framework\TestCase;

final class AssistantMutationIntentTest extends TestCase
{
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
