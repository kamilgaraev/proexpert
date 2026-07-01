<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Reports;

use App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\GenerateRagPdfReportTool;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComposerInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportPdfWriterInterface;
use App\Models\Organization;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class GenerateRagPdfReportToolTest extends TestCase
{
    public function test_creates_pdf_from_grounded_rag_report_with_sources(): void
    {
        $composer = new FakeAssistantReportComposer([
            'title' => 'Отчет: PDF-отчет по проекту Кирпичный дом Лесной двор',
            'summary' => 'По найденным источникам: проект в работе.',
            'key_findings' => ['Проект находится в работе, есть риск задержки поставки.'],
            'sections' => [
                [
                    'title' => 'Паспорт проекта',
                    'source_title' => 'Паспорт проекта',
                    'fact' => 'Проект в работе.',
                    'items' => ['Проект в работе.'],
                    'meta' => ['Тип: Проект', 'Релевантность: 91%'],
                ],
            ],
            'risks' => ['Есть риск задержки поставки кирпича.'],
            'next_actions' => ['Проверить источник.'],
            'sources' => [
                [
                    'title' => 'Паспорт проекта',
                    'display_title' => 'Паспорт проекта',
                    'project_id' => 88,
                    'excerpt' => 'Проект в работе.',
                    'reference_excerpt' => 'Проект в работе.',
                    'meta' => ['Тип: Проект', 'Релевантность: 91%'],
                ],
            ],
            'limitations' => [],
            'has_sufficient_data' => true,
            'period_label' => 'весь доступный период',
            'organization_name' => 'ПроХелпер',
            'generated_by' => 'Инженер',
        ]);
        $writer = new FakeAssistantReportPdfWriter;
        $tool = new GenerateRagPdfReportTool($composer, $writer);

        $result = $tool->execute([
            'report_type' => 'generic_rag',
            'project_id' => 88,
            'query' => 'PDF-отчет по проекту Кирпичный дом Лесной двор',
        ], $this->user(), $this->organization());

        $this->assertSame('success', $result['status']);
        $this->assertSame('generic_rag', $result['report_type']);
        $this->assertSame(88, $composer->lastInput['project_id']);
        $this->assertSame('PDF-отчет по проекту Кирпичный дом Лесной двор', $composer->lastInput['query']);
        $this->assertSame('reports.operational-summary-pdf', $writer->view);
        $this->assertSame('Паспорт проекта', $writer->data['report']['rag_report']['sources'][0]['title']);
        $this->assertSame('primary', $writer->data['report']['rag_context_mode']);
        $this->assertFalse($writer->data['report']['has_structured_data']);
        $this->assertSame('Проект находится в работе, есть риск задержки поставки.', $writer->data['report']['key_findings'][0]);
        $this->assertSame('Факты', $writer->data['report']['summary_cards'][1]['label']);
        $this->assertSame('Действия', $writer->data['report']['summary_cards'][3]['label']);
        $this->assertSame('s3', $result['storage_disk']);
        $this->assertStringStartsWith('org-72/reports/', $result['storage_path']);
    }

    public function test_does_not_create_pdf_when_rag_sources_are_missing(): void
    {
        $composer = new FakeAssistantReportComposer([
            'title' => 'Отчет: нет данных',
            'summary' => 'данных недостаточно для формирования подтвержденного отчета по найденным источникам.',
            'sections' => [],
            'risks' => [],
            'next_actions' => [],
            'sources' => [],
            'limitations' => ['По запросу не найдено релевантных источников.'],
            'has_sufficient_data' => false,
        ]);
        $writer = new FakeAssistantReportPdfWriter;
        $tool = new GenerateRagPdfReportTool($composer, $writer);

        $result = $tool->execute([
            'query' => 'PDF-отчет по неизвестной теме',
        ], $this->user(), $this->organization());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('недостаточно данных', mb_strtolower($result['message']));
        $this->assertFalse($writer->stored);
    }

    private function organization(): Organization
    {
        $organization = new Organization;
        $organization->id = 72;
        $organization->name = 'ПроХелпер';

        return $organization;
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 7;
        $user->name = 'Инженер';

        return $user;
    }
}

final class FakeAssistantReportComposer implements AssistantReportComposerInterface
{
    /**
     * @var array<string, mixed>
     */
    public array $lastInput = [];

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        private readonly array $report
    ) {}

    public function compose(Organization $organization, User $user, array $input): array
    {
        $this->lastInput = $input;

        return $this->report;
    }
}

final class FakeAssistantReportPdfWriter implements AssistantReportPdfWriterInterface
{
    public bool $stored = false;

    public ?string $view = null;

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function store(string $view, array $data, Organization $organization, string $filenamePrefix): array
    {
        $this->stored = true;
        $this->view = $view;
        $this->data = $data;

        return [
            'pdf_url' => 'https://storage.example.test/org-72/reports/'.$filenamePrefix.'.pdf',
            'filename' => $filenamePrefix.'.pdf',
            'storage_disk' => 's3',
            'storage_path' => 'org-72/reports/'.$filenamePrefix.'.pdf',
            'expires_at' => '2026-07-02T12:00:00+03:00',
            'size' => 1234,
        ];
    }
}
