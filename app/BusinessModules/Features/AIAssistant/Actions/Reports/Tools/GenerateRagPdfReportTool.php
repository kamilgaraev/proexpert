<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComposerInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportPdfWriterInterface;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;
use Throwable;

final readonly class GenerateRagPdfReportTool implements AIToolInterface
{
    public function __construct(
        private AssistantReportComposerInterface $composer,
        private AssistantReportPdfWriterInterface $pdfWriter
    ) {}

    public function getName(): string
    {
        return 'generate_rag_pdf_report';
    }

    public function getDescription(): string
    {
        return 'Формирует PDF-отчет по теме из доступных источников базы знаний AI-ассистента. Использовать только при явном запросе на PDF, файл или отчет с источниками.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_type' => [
                    'type' => 'string',
                    'description' => 'Тип RAG-отчета. Для универсального отчета используйте generic_rag.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Исходный запрос или тема отчета.',
                ],
                'topic' => [
                    'type' => 'string',
                    'description' => 'Краткая тема отчета, если отличается от исходного запроса.',
                ],
                'period' => [
                    'type' => 'string',
                    'description' => 'Текстовое описание периода, если пользователь его указал.',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Дата начала периода в формате YYYY-MM-DD.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Дата окончания периода в формате YYYY-MM-DD.',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта для ограничения источников.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if (! $user instanceof User) {
            return [
                'status' => 'error',
                'message' => 'Недостаточно прав для формирования отчета.',
            ];
        }

        $input = $this->input($arguments);
        $report = $this->composer->compose($organization, $user, $input);

        if (($report['has_sufficient_data'] ?? false) !== true) {
            return [
                'status' => 'error',
                'message' => 'Недостаточно данных для формирования PDF-отчета по найденным источникам.',
                'report_type' => $input['report_type'],
                'limitations' => array_values(is_array($report['limitations'] ?? null) ? $report['limitations'] : []),
            ];
        }

        try {
            $stored = $this->pdfWriter->store(
                'reports.operational-summary-pdf',
                ['report' => $this->pdfReport($report, $organization, $user)],
                $organization,
                $this->filenamePrefix((string) $input['report_type'])
            );
        } catch (Throwable) {
            return [
                'status' => 'error',
                'message' => 'Не удалось сформировать PDF-отчет. Попробуйте повторить запрос или уточнить тему.',
            ];
        }

        return [
            'status' => 'success',
            'message' => 'Отчет по найденным источникам сформирован.',
            'report_type' => $input['report_type'],
            'period' => $input['period'] ?? 'весь доступный период',
            ...$stored,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function input(array $arguments): array
    {
        return array_filter([
            'report_type' => $this->stringArgument($arguments['report_type'] ?? null) ?? 'generic_rag',
            'query' => $this->stringArgument($arguments['query'] ?? null)
                ?? $this->stringArgument($arguments['topic'] ?? null)
                ?? 'отчет по данным базы знаний',
            'topic' => $this->stringArgument($arguments['topic'] ?? null),
            'period' => $this->stringArgument($arguments['period'] ?? null),
            'date_from' => $this->stringArgument($arguments['date_from'] ?? null),
            'date_to' => $this->stringArgument($arguments['date_to'] ?? null),
            'project_id' => is_numeric($arguments['project_id'] ?? null) ? (int) $arguments['project_id'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function pdfReport(array $report, Organization $organization, User $user): array
    {
        $sources = array_values(is_array($report['sources'] ?? null) ? $report['sources'] : []);
        $risks = array_values(is_array($report['risks'] ?? null) ? $report['risks'] : []);
        $sections = array_values(is_array($report['sections'] ?? null) ? $report['sections'] : []);
        $nextActions = array_values(is_array($report['next_actions'] ?? null) ? $report['next_actions'] : []);
        $keyFindings = $this->stringList($report['key_findings'] ?? []);

        return [
            'title' => (string) ($report['title'] ?? 'Отчет по найденным источникам'),
            'description' => (string) ($report['summary'] ?? ''),
            'period_label' => (string) ($report['period_label'] ?? 'весь доступный период'),
            'generated_at' => (string) ($report['generated_at'] ?? now()->format('d.m.Y H:i')),
            'organization_name' => (string) ($report['organization_name'] ?? $organization->name ?? 'Организация'),
            'generated_by' => (string) ($report['generated_by'] ?? $user->name ?? ''),
            'summary_cards' => [
                ['label' => 'Источники', 'value' => (string) count($sources), 'hint' => 'найдено'],
                ['label' => 'Факты', 'value' => (string) count($sections), 'hint' => 'в отчете'],
                ['label' => 'Риски', 'value' => (string) count($risks), 'hint' => 'по источникам'],
                ['label' => 'Действия', 'value' => (string) count($nextActions), 'hint' => 'рекомендовано'],
            ],
            'key_findings' => $keyFindings !== [] ? $keyFindings : $this->fallbackKeyFindings($report),
            'sections' => [],
            'rag_report' => $report,
            'rag_context_mode' => 'primary',
            'has_structured_data' => false,
            'sources' => $sources,
            'limitations' => array_values(is_array($report['limitations'] ?? null) ? $report['limitations'] : []),
        ];
    }

    private function filenamePrefix(string $reportType): string
    {
        $slug = Str::slug($reportType, '_');

        return $slug !== '' ? $slug : 'rag_report';
    }

    private function stringArgument(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '', $values),
            static fn (string $value): bool => $value !== ''
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     * @return string[]
     */
    private function fallbackKeyFindings(array $report): array
    {
        $summary = $this->stringArgument($report['summary'] ?? null);

        return $summary !== null ? [$summary] : [];
    }
}
