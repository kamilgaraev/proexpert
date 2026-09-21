<?php

declare(strict_types=1);

namespace Tests\Unit\ConstructionJournal;

use App\Services\ConstructionJournal\GeneralJournalDocumentDefinition;
use App\Services\ConstructionJournal\GeneralJournalDocumentRenderService;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GeneralJournalDocumentRenderTest extends TestCase
{
    private mixed $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    public function test_definition_matches_paper_form_and_excludes_implicit_number_column(): void
    {
        self::assertSame('1026-paper-v1', GeneralJournalDocumentDefinition::TEMPLATE_VERSION);
        self::assertArrayHasKey('project_name', GeneralJournalDocumentDefinition::headerFields());
        self::assertSame(10, count(GeneralJournalDocumentDefinition::representativeGroups()));
        self::assertArrayHasKey('operator_control', GeneralJournalDocumentDefinition::representativeGroups());
        self::assertStringContainsString('перечень исполнительной документации', mb_strtolower(GeneralJournalDocumentDefinition::sections()[5]['title']));
        self::assertStringNotContainsString('капитальном ремонте объекта', GeneralJournalDocumentDefinition::sections()[6]['title']);

        $expectedColumns = [5, 4, 4, 6, 2, 6];
        foreach (GeneralJournalDocumentDefinition::sections() as $number => $section) {
            self::assertCount($expectedColumns[$number - 1], $section['columns']);
            self::assertArrayNotHasKey('number', $section['columns']);
        }
    }

    public function test_render_contains_all_sections_empty_rows_and_snapshot_values_escaped(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['journal']['project_name'] = '<script>alert(1)</script>';
        $snapshot['sections'][3][0]['works'] = str_repeat('Работа с материалами и испытаниями ', 40);

        $html = $this->service()->html($snapshot);

        self::assertStringContainsString('Проект для оформления бумажного журнала', $html);
        self::assertStringContainsString('Общий журнал, в котором ведется учет выполнения работ', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('РАЗДЕЛ 1', $html);
        self::assertStringContainsString('РАЗДЕЛ 6', $html);
        self::assertStringContainsString('(продолжение)', $html);
        self::assertStringContainsString('Подпись', $html);
        self::assertStringNotContainsString('криптографически подписан', $html);
    }

    public function test_render_snapshot_returns_pdf(): void
    {
        $pdf = $this->service()->renderSnapshot($this->snapshot());

        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_snapshot_size_is_bounded(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['sections'][3][0]['works'] = str_repeat('x', 2 * 1024 * 1024);

        $this->expectException(\DomainException::class);
        $this->service()->html($snapshot);
    }

    private function service(): GeneralJournalDocumentRenderService
    {
        return $this->app->make(GeneralJournalDocumentRenderService::class);
    }

    private function snapshot(): array
    {
        return [
            'template_version' => '1026-paper-v1',
            'journal' => [
                'number' => '12',
                'project_name' => 'Объект',
                'project_address' => 'Москва',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ],
            'profile' => [
                'header' => [
                    'developer' => 'ООО «Застройщик»',
                    'technical_customer' => 'ООО «Техзаказчик»',
                    'permit' => 'Разрешение 77-01/2026',
                    'project_documentation' => 'Проект 01-2026',
                    'expertise' => 'Заключение 02-2026',
                    'state_supervision' => 'Мосгосстройнадзор',
                    'object_characteristics' => 'Жилой дом',
                    'construction_start' => '2026-01-01',
                    'construction_end' => '2026-12-31',
                    'journal_page_count' => '16',
                    'journal_registration' => 'Регистрационная запись',
                ],
                'representatives' => [
                    'developer' => [['name' => 'Иванов И.И.', 'position' => 'Инженер', 'authority' => 'Приказ 1', 'nrs_number' => 'NRS-1']],
                ],
                'title_changes' => [['date' => '2026-01-02', 'change' => 'Исправление', 'representative' => 'Иванов И.И.', 'authority' => 'Приказ 2']],
            ],
            'sections' => [
                1 => [['organization' => 'ООО «Подрядчик»', 'employee' => 'Петров П.П.', 'started' => '2026-01-01', 'finished' => '2026-12-31', 'representative' => 'Сидоров С.С.']],
                2 => [['journal' => 'Спецжурнал', 'keeper' => 'Петров П.П.', 'transferred_at' => '2026-01-03', 'recipient' => 'Иванов И.И.']],
                3 => [['date' => '2026-01-04', 'conditions' => 'Нормальные', 'works' => 'Монтаж', 'representative' => 'Петров П.П.']],
                4 => [['control' => 'Входной контроль', 'defects' => '', 'due_at' => '', 'controller' => 'Иванов И.И.', 'resolved_at' => '', 'reviewer' => '']],
                5 => [['document' => 'Акт КС-2', 'signed' => '2026-01-05']],
                6 => [['date' => '2026-01-06', 'inspection' => 'Проверка', 'due_at' => '', 'inspector' => 'Надзор', 'resolved_at' => '', 'reviewer' => '']],
            ],
            'revision' => 1,
            'correction_reason' => null,
        ];
    }
}
