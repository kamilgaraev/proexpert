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
        self::assertFalse(AssistantTemporalIntent::hasExplicitWindow('Покажи действующие договоры нашего объекта на сегодня'));
        self::assertFalse(AssistantTemporalIntent::hasExplicitWindow('Какие активные договоры действуют на сегодняшний день?'));
        self::assertTrue(AssistantTemporalIntent::hasExplicitWindow('Какие активные договоры заканчиваются сегодня?'));
        self::assertTrue(AssistantTemporalIntent::hasExplicitWindow('Какие активные договоры имеют дату окончания на сегодня?'));
    }

    public function test_current_settings_are_not_an_historical_or_future_source(): void
    {
        foreach (['действовал в сентябре', 'были в прошлом месяце', 'были вчера', 'на дату 2026-10-01',
            'раньше', 'будут завтра', 'в следующем месяце', 'через неделю', 'неделю назад',
            'через три дня', 'спустя два месяца', 'пять дней назад', 'на следующий квартал',
            'на следующую пятницу', 'в прошлом квартале', 'на следующий рабочий день', 'на выходных'] as $query) {
            self::assertTrue(AssistantTemporalIntent::hasNonCurrentScope($query), $query);
        }
        foreach (['Какой срок подотчёта?', 'Сколько дней даётся на подотчёт?', 'Какие настройки действуют сейчас?',
            'За сколько дней напоминают об авансовом отчёте?',
            'Будут ли уведомлять администратора о просроченном авансовом отчёте?',
            'Будет ли администратор получать уведомление?'] as $query) {
            self::assertFalse(AssistantTemporalIntent::hasNonCurrentScope($query), $query);
        }
        self::assertFalse(AssistantTemporalIntent::hasNonCurrentScope('Сколько составляет лимит подотчётных средств — 5000 рублей?'));
        foreach (['5000.00 рублей', '5000.50 RUB', '12.05 рублей', '12.05 ₽', '5000,00 рублей'] as $amount) {
            $query = 'Сколько составляет лимит подотчётных средств — '.$amount.'?';
            self::assertFalse(AssistantTemporalIntent::hasNonCurrentScope($query), $query);
            self::assertFalse(AssistantTemporalIntent::hasExplicitWindow($query), $query);
        }
        self::assertTrue(AssistantTemporalIntent::hasNonCurrentScope('Какие настройки были 12.05?'));
        self::assertTrue(AssistantTemporalIntent::hasNonCurrentScope('Какие настройки подотчёта в 2026 году?'));
        foreach (['по состоянию на сегодня', 'по состоянию на сейчас', 'по состоянию на текущую дату'] as $query) {
            self::assertFalse(AssistantTemporalIntent::hasNonCurrentScope($query), $query);
        }
    }
}
