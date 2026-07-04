<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantResolvedPeriod;
use App\BusinessModules\Features\AIAssistant\Services\Agent\AssistantPeriodResolver;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AssistantPeriodResolverTest extends TestCase
{
    #[DataProvider('periodProvider')]
    public function test_resolves_russian_periods(string $text, ?string $from, ?string $to, string $label): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'));
        $period = $resolver->resolve($text);

        $this->assertPeriod($period, $from, $to, $label, $text);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string|null, 3: string}>
     */
    public static function periodProvider(): array
    {
        return [
            'last month' => ['за последний месяц', '2026-04-04', '2026-05-04', 'Последний месяц'],
            'two months as words' => ['за два месяца', '2026-03-04', '2026-05-04', 'За 2 месяца'],
            'last three months as words' => ['за последние три месяца', '2026-02-04', '2026-05-04', 'За 3 месяца'],
            'last three months with digit' => ['последние 3 месяца', '2026-02-04', '2026-05-04', 'За 3 месяца'],
            'quarter' => ['за квартал', '2026-02-04', '2026-05-04', 'Последний квартал'],
            'half year' => ['за полгода', '2025-11-04', '2026-05-04', 'Последние полгода'],
            'two weeks' => ['за 2 недели', '2026-04-20', '2026-05-04', 'За 2 недели'],
            'two weeks as words' => ['за две недели', '2026-04-20', '2026-05-04', 'За 2 недели'],
            'three weeks' => ['за 3 недели', '2026-04-13', '2026-05-04', 'За 3 недели'],
            'three weeks ago' => ['3 недели назад', '2026-04-07', '2026-04-14', '3 недели назад'],
            'november nearest past year' => ['за ноябрь', '2025-11-01', '2025-11-30', 'Ноябрь 2025'],
            'may nearest past year' => ['за май', '2025-05-01', '2025-05-31', 'Май 2025'],
            'explicit range' => ['с 1.04.2026 по 01.05.2026', '2026-04-01', '2026-05-01', 'Указанный период'],
            'current month' => ['за этот месяц', '2026-05-01', '2026-05-04', 'Текущий месяц'],
            'previous month' => ['за прошлый месяц', '2026-04-01', '2026-04-30', 'Прошлый месяц'],
            'current year' => ['за текущий год', '2026-01-01', '2026-05-04', 'Текущий год'],
            'previous year' => ['за прошлый год', '2025-01-01', '2025-12-31', 'Прошлый год'],
            'project start through today' => ['с начала проекта по сегодняшний день', null, '2026-05-04', 'С начала проекта по текущий день'],
            'all available period' => ['за весь доступный период', null, null, 'Весь доступный период'],
        ];
    }

    public function test_array_with_date_boundaries_returns_period(): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'));
        $period = $resolver->resolve([
            'date_from' => '2026-04-01',
            'date_to' => '2026-05-01',
            'label' => 'Апрель',
            'source_text' => 'ручной период',
        ]);

        $this->assertPeriod($period, '2026-04-01', '2026-05-01', 'Апрель', 'ручной период');
        $this->assertSame([
            'date_from' => '2026-04-01',
            'date_to' => '2026-05-01',
            'label' => 'Апрель',
            'source_text' => 'ручной период',
        ], $period?->toArray());
    }

    public function test_materials_word_does_not_match_may(): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'));

        $this->assertNull($resolver->resolve('материалы'));
    }

    public function test_invalid_explicit_range_returns_null(): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'));

        $this->assertNull($resolver->resolve('с 32.04.2026 по 01.05.2026'));
        $this->assertNull($resolver->resolve('с 02.05.2026 по 01.05.2026'));
    }

    public function test_invalid_array_boundaries_return_null(): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'));

        $this->assertNull($resolver->resolve(['date_from' => '2026-99-99', 'date_to' => '2026-05-01']));
        $this->assertNull($resolver->resolve(['date_from' => '2026-05-02', 'date_to' => '2026-05-01']));
        $this->assertNull($resolver->resolve(['date_from' => [], 'date_to' => '2026-05-01']));
    }

    public function test_month_expressions_do_not_overflow_on_month_end(): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-31 12:00:00', 'Europe/Moscow'));

        $this->assertPeriod($resolver->resolve('за последний месяц'), '2026-04-30', '2026-05-31', 'Последний месяц', 'за последний месяц');
        $this->assertPeriod($resolver->resolve('за прошлый месяц'), '2026-04-01', '2026-04-30', 'Прошлый месяц', 'за прошлый месяц');
    }

    public function test_array_label_and_source_ignore_nested_values(): void
    {
        $resolver = new AssistantPeriodResolver(CarbonImmutable::parse('2026-05-04 02:09:00', 'Europe/Moscow'));
        $period = $resolver->resolve([
            'date_from' => '2026-04-01',
            'date_to' => '2026-05-01',
            'label' => [],
            'source_text' => [],
        ]);

        $this->assertPeriod($period, '2026-04-01', '2026-05-01', '2026-04-01 - 2026-05-01', '');
    }

    private function assertPeriod(
        ?AssistantResolvedPeriod $period,
        ?string $dateFrom,
        ?string $dateTo,
        string $label,
        string $sourceText
    ): void {
        $this->assertInstanceOf(AssistantResolvedPeriod::class, $period);
        $this->assertSame($dateFrom, $period->dateFrom);
        $this->assertSame($dateTo, $period->dateTo);
        $this->assertSame($label, $period->label);
        $this->assertSame($sourceText, $period->sourceText);
    }
}
