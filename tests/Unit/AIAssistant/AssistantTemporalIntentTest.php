<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding\AssistantTemporalIntent;
use PHPUnit\Framework\TestCase;

final class AssistantTemporalIntentTest extends TestCase
{
    public function test_bounded_roster_does_not_ignore_explicit_windows(): void
    {
        foreach (['в октябре 2026 года', 'с 2026-10-01 по 2026-10-31', 'с 01.10.2026 по 31.10.2026',
            'за семь дней', 'на следующей неделе', 'в этом году', 'завтра', 'сегодня'] as $query) {
            self::assertTrue(AssistantTemporalIntent::hasExplicitWindow($query), $query);
        }
        self::assertFalse(AssistantTemporalIntent::hasExplicitWindow('Какие договоры просрочены по сроку окончания?'));
    }

    public function test_current_settings_are_not_an_historical_or_future_source(): void
    {
        foreach (['действовал в сентябре', 'были в прошлом месяце', 'были вчера', 'на дату 2026-10-01',
            'раньше', 'будут завтра', 'в следующем месяце', 'через неделю', 'неделю назад',
            'через три дня', 'спустя два месяца', 'пять дней назад'] as $query) {
            self::assertTrue(AssistantTemporalIntent::hasNonCurrentScope($query), $query);
        }
        foreach (['Какой срок подотчёта?', 'Сколько дней даётся на подотчёт?', 'Какие настройки действуют сейчас?',
            'За сколько дней напоминают об авансовом отчёте?',
            'Будут ли уведомлять администратора о просроченном авансовом отчёте?',
            'Будет ли администратор получать уведомление?'] as $query) {
            self::assertFalse(AssistantTemporalIntent::hasNonCurrentScope($query), $query);
        }
    }
}
