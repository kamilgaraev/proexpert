<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentPrintTemplate;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutiveDocumentPrintTemplateTest extends TestCase
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

    #[DataProvider('documentTypes')]
    public function test_supported_document_type_renders_its_frozen_fields(string $type, array $profile, string $marker): void
    {
        $html = $this->app->make(ExecutiveDocumentPrintTemplate::class)->html($this->snapshot($type, $profile), '344-369-v1');

        self::assertStringContainsString('АКТ ', $html);
        self::assertStringContainsString('1. ', $html);
        self::assertStringContainsString($marker, $html);
        self::assertStringContainsString('Подписи представителей', $html);
        self::assertStringContainsString('Журнал', $html);
    }

    public function test_mapper_escapes_long_untrusted_values_and_keeps_unknown_values_empty(): void
    {
        $value = '<script>alert(1)</script> '.str_repeat('Длинный реквизит ', 30);
        $snapshot = $this->snapshot('hidden_work_act', [
            'presented_works' => $value,
            'started_at' => '2026-01-01',
            'finished_at' => '2026-01-03',
            'next_works_permission' => null,
        ]);
        $html = $this->app->make(ExecutiveDocumentPrintTemplate::class)->html($snapshot, '344-369-v1');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('Печать', $html);
        self::assertStringNotContainsString('Заключение: соответствует', $html);
    }

    public function test_unsupported_template_version_is_rejected(): void
    {
        $this->expectException(\DomainException::class);
        $this->app->make(ExecutiveDocumentPrintTemplate::class)->html($this->snapshot('hidden_work_act', []), '344-369-v2');
    }

    public function test_responsible_structure_keeps_normative_points_in_source_order(): void
    {
        $html = $this->app->make(ExecutiveDocumentPrintTemplate::class)->html($this->snapshot('responsible_structure_act', [
            'presented_structures' => 'Каркас',
            'hidden_work_acts' => 'АОСР-7',
            'materials_quality_documents' => 'Сертификат-8',
            'compliance_documents' => 'Схема-9',
            'inspection_results' => 'Испытание-10',
            'acceptance_decision' => 'remarks_required',
        ]), '344-369-v1');

        self::assertStringContainsString('3. Освидетельствованы скрытые работы', $html);
        self::assertStringContainsString('4. При выполнении строительных конструкций применены материалы', $html);
        self::assertStringContainsString('5. Предъявлены документы, подтверждающие соответствие конструкций', $html);
        self::assertStringContainsString('6. Проведены необходимые испытания и опробования', $html);
        self::assertStringContainsString('Требуются замечания', $html);
    }

    public static function documentTypes(): array
    {
        return self::fixtures();
    }

    public function test_hidden_work_keeps_conditional_signers_when_not_filled(): void
    {
        $html = $this->app->make(ExecutiveDocumentPrintTemplate::class)->html($this->snapshot('hidden_work_act', []), '344-369-v1');
        self::assertSame(2, substr_count($html, 'Представитель лица, осуществляющего подготовку проектной документации (при привлечении)'));
        self::assertSame(2, substr_count($html, 'Представитель лица, выполнившего работы (при выполнении по договору)'));
        self::assertLessThan(strpos($html, 'Иван Иванов'), strpos($html, 'Представитель застройщика'));
    }

    public function test_prints_act_number_and_frozen_relation_requisites_without_array_dump(): void
    {
        $snapshot = $this->snapshot('hidden_work_act', ['act_number' => 'АОСР-17', 'presented_works' => 'Армирование']);
        $snapshot['relations'] = [[
            'relation_type' => 'quality_documents', 'target_id' => 42,
            'target_version' => ['version_id' => 71, 'document_id' => 42, 'content_hash' => str_repeat('a', 64)],
            'document_snapshot' => ['title' => 'Паспорт бетона', 'number' => 'П-13', 'document_date' => '2026-01-02', 'version_number' => '2'],
        ]];
        $html = $this->app->make(ExecutiveDocumentPrintTemplate::class)->html($snapshot, '344-369-v1');
        self::assertStringContainsString('№ АОСР-17', $html);
        self::assertStringContainsString('Паспорт бетона № П-13 от 2026-01-02', $html);
        self::assertStringNotContainsString('content_hash', $html);
        self::assertStringNotContainsString('Array', $html);
        self::assertStringContainsString('Представитель лица, осуществляющего строительство, по вопросам строительного контроля', $html);
    }

    private static function fixtures(): array
    {
        return [
            'hidden work' => ['hidden_work_act', ['presented_works' => 'Арматура', 'started_at' => '2026-01-01', 'finished_at' => '2026-01-03', 'next_works_permission' => 'Разрешено'], 'Арматура'],
            'axis layout' => ['axis_layout_act', ['axis_layout_text' => 'Оси 1–4', 'axis_fixing_text' => 'Закреплены'], 'Оси 1'],
            'geodetic base' => ['geodetic_base_acceptance_act', ['geodetic_base_description' => 'Реперы', 'base_acceptance_documents' => 'Акт передачи'], 'Реперы'],
            'structure' => ['responsible_structure_act', ['presented_structures' => 'Каркас', 'structure_location' => 'Секция А', 'started_at' => '2026-01-01', 'finished_at' => '2026-01-04', 'acceptance_decision' => 'accepted'], 'Каркас'],
            'network' => ['engineering_network_section_act', ['network_type' => 'ВК', 'network_section_boundaries' => 'ПК 1–ПК 2', 'technical_conditions' => 'ТУ-1', 'started_at' => '2026-01-01', 'finished_at' => '2026-01-04', 'compliance_conclusion' => 'Соответствует'], 'ВК'],
        ];
    }

    private function snapshot(string $type, array $profile): array
    {
        return [
            'document_type' => $type,
            'document' => [
                'title' => 'Акт ИД', 'document_date' => '2026-01-05', 'copies_count' => 2,
                'participants' => [['role' => 'Заказчик', 'name' => 'ООО «МОСТ»']],
                'signatories' => [['role' => 'construction_representative', 'name' => 'Иван Иванов', 'organization' => 'ООО «МОСТ»', 'authority_document' => 'Доверенность 1']],
            ],
            'profile_data' => $profile,
            'project' => ['name' => 'Объект', 'address' => 'Москва'],
            'relations' => [['label' => 'Журнал', 'relation_type' => 'journal_entry', 'target_version' => 'v-17', 'domain_snapshot' => ['title' => 'Журнал'], 'metadata' => []]],
            'source_version_id' => 10,
            'source_version_number' => 'v-17',
        ];
    }
}
