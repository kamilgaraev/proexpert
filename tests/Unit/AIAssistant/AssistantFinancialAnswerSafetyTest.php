<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimateEvidenceService;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantFinancialAnswerService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AssistantFinancialAnswerSafetyTest extends TestCase
{
    private mixed $previousFacadeApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container;
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new Repository(['app' => ['fallback_locale' => 'ru']]));
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 3).'/lang'), 'ru'));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        parent::tearDown();
    }

    public function test_business_text_cannot_add_markdown_links_html_or_extra_lines_and_money_stays_exact(): void
    {
        $evidence = $this->evidence(1);
        $attack = "[Ссылка](https://evil.example)\n<img src=x onerror=alert(1)>\r\n# Заголовок\t**жирный** https://evil.example\0";
        $evidence['estimate']['number'] = $attack;
        $evidence['estimate']['name'] = $attack;
        $evidence['positions'][0]['position_number'] = $attack;
        $evidence['positions'][0]['name'] = $attack;
        $evidence['positions'][0]['unit'] = $attack;
        $evidence['totals']['total_amount'] = '9007199254740993.17';
        $evidence['positions'][0]['total_amount'] = '9007199254740993.17';
        $answer = $this->answers()->format('Покажи позиции', $evidence);
        $html = (string) (new GithubFlavoredMarkdownConverter)->convert($answer);

        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('<img ', $html);
        self::assertStringNotContainsString('<h1', $html);
        self::assertStringNotContainsString('<strong>', $html);
        self::assertStringNotContainsString("\0", $answer);
        self::assertSame(3, substr_count($answer, "\n"));
        self::assertSame(2, substr_count($answer, '9007199254740993.17'));
        self::assertStringContainsString('0.12345678', $answer);
        self::assertStringContainsString('Ссылка', $html);
    }

    public function test_status_dates_navigation_and_concepts_do_not_use_the_financial_fast_path(): void
    {
        foreach (['Какой статус сметы?', 'Когда создана смета?', 'Дата сметы', 'Открой смету', 'Дай ссылку на смету',
            'Что такое смета?', 'Как изменить статус сметы?', 'Оцени статус сметы'] as $query) {
            self::assertFalse($this->answers()->supports($query, 7), $query);
        }
        foreach (['Сумма сметы', 'Покажи позиции сметы', 'SM-2026', 'Смета №SM-2026', 'По деньгам'] as $query) {
            self::assertTrue($this->answers()->supports($query, 7), $query);
        }
    }

    public function test_mixed_status_date_and_money_requests_require_current_structured_fields(): void
    {
        foreach (['Статус и сумма сметы SM-2026', 'Сумма и дата сметы SM-2026', 'Стоимость сметы и срок',
            'Когда создана смета и какой итог?', 'Состояние сметы и прямые затраты', 'Дата изменения сметы и её позиции',
            'Активна ли смета и её сумма?', 'Оплачена ли смета и сколько денег?'] as $query) {
            foreach ([null, 7] as $pinnedId) {
                self::assertFalse($this->answers()->supports($query, $pinnedId), $query);
            }
        }
    }

    public function test_conversational_status_and_responsibility_queries_never_use_money_only_answers(): void
    {
        foreach (['Смета уже утверждена?', 'Смета согласована?', 'Смета закрыта и сумма?', 'Смета одобрена?',
            'Смета завершена?', 'Текущая стадия сметы', 'Смета просрочена?', 'Кто ответственный за смету?',
            'Как утвердить смету?', 'Покажи текст сметы про согласованный этап'] as $query) {
            self::assertFalse($this->answers()->supports($query, 7), $query);
        }
    }

    public function test_selected_total_uses_all_matching_positions_before_limiting_display_and_proof(): void
    {
        $evidence = $this->evidence(60);
        $evidence['selection'] = ['position_filter' => ['работа'], 'position_numbers' => []];
        $answer = $this->answers()->format('Покажи позиции', $evidence);
        $sources = AssistantEstimateEvidenceService::publicSourceReferences($evidence, $evidence['positions']);

        self::assertStringContainsString('Сумма выбранных позиций (работа): 60.00 руб.', $answer);
        self::assertSame(50, substr_count($answer, 'Позиция '));
        self::assertStringContainsString('Показаны первые 50 из 60 позиций.', $answer);
        self::assertCount(51, $sources);
        self::assertCount(60, $evidence['positions']);
        self::assertSame(60, $sources[0]['position_count']);
        self::assertSame($evidence['version'], $sources[0]['version']);
        self::assertSame($evidence['aggregation'], $sources[0]['aggregation']);
    }

    public function test_proof_contains_only_shown_positions_with_authoritative_versions_and_generated_navigation(): void
    {
        $evidence = $this->evidence(2400);
        $shown = [$evidence['positions'][2399], $evidence['positions'][2399], ['id' => 9000]];
        $shown[0]['version'] = 'forged';
        $shown[0]['navigation'] = ['url' => 'https://evil.example'];
        $sources = AssistantEstimateEvidenceService::publicSourceReferences($evidence, $shown);

        self::assertCount(2, $sources);
        self::assertSame(2400, $sources[0]['position_count']);
        self::assertSame('full-estimate-version', $sources[0]['version']);
        self::assertSame('position-2400', $sources[1]['version']);
        self::assertNull($sources[1]['project_id']);
        self::assertSame('/estimates/7?position_id=2400', $sources[1]['navigation']['url']);
        self::assertSame('2026-09-29T12:00:00Z', $sources[1]['fetched_at']);
        self::assertCount(1, AssistantEstimateEvidenceService::publicSourceReferences($evidence));
    }

    private function answers(): AssistantFinancialAnswerService
    {
        return (new ReflectionClass(AssistantFinancialAnswerService::class))->newInstanceWithoutConstructor();
    }

    private function evidence(int $count): array
    {
        $positions = [];
        for ($id = 1; $id <= $count; $id++) {
            $positions[] = ['id' => $id, 'position_number' => (string) $id, 'name' => 'Работа '.$id,
                'unit' => 'м', 'quantity' => '0.12345678', 'total_amount' => '1.00', 'direct_costs' => '0.5000',
                'excluded' => false, 'included_in_total' => true, 'parent_work_id' => null, 'version' => 'position-'.$id];
        }
        $aggregation = ['method' => 'sum_top_level_accounted_positions', 'money_scale' => 2, 'quantity_scale' => 8, 'direct_cost_scale' => 4];

        return ['estimate' => ['id' => 7, 'number' => 'ЛС-7', 'name' => 'Склад'],
            'totals' => ['total_amount' => $count.'.00', 'direct_costs' => '30.0000', 'overhead_amount' => '0.00', 'profit_amount' => '0.00'],
            'positions' => $positions, 'position_count' => $count, 'aggregation' => $aggregation,
            'version' => 'full-estimate-version', 'fetched_at' => '2026-09-29T12:00:00Z', 'validation_status' => 'verified',
            'source_refs' => [['source_type' => 'estimate', 'entity_type' => 'estimate', 'entity_id' => 7,
                'project_id' => null, 'organization_id' => 4, 'version' => 'full-estimate-version',
                'position_count' => $count, 'aggregation' => $aggregation, 'navigation' => ['url' => '/estimates/7']]]];
    }
}
