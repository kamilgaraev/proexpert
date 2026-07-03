<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportEnricher;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportService;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportPeriodFilter;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportComposerInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportCatalog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class GenerateOperationalPdfReportTool implements AIToolInterface
{
    public function __construct(
        private readonly AssistantOperationalReportService $reportService,
        private readonly AssistantReportCatalog $reportCatalog,
        private readonly AssistantReportComposerInterface $reportComposer,
        private readonly AssistantOperationalReportEnricher $reportEnricher,
        private readonly FileService $fileService,
        private readonly AssistantOperationalReportPeriodFilter $periodFilter = new AssistantOperationalReportPeriodFilter
    ) {}

    public function getName(): string
    {
        return 'generate_operational_pdf_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF для дополнительных операционных отчетов МОСТ: проекты, закупки, заказы поставщикам, предложения поставщиков, заявки со стройплощадки, сметы, качество, безопасность, техника и посещаемость.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'report_type' => [
                    'type' => 'string',
                    'description' => 'Тип отчета: projects_summary, procurement_requests, purchase_orders, supplier_proposals, site_requests, estimates_summary, quality_defects, safety_incidents, machinery_utilization или workforce_attendance.',
                ],
                'period' => [
                    'type' => 'string',
                    'description' => 'Текстовое описание периода, например "за месяц" или "за прошлую неделю".',
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
                    'description' => 'ID проекта для фильтрации, если отчет формируется по конкретному проекту.',
                ],
            ],
            'required' => ['report_type'],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $reportType = $this->normalizeReportType($arguments['report_type'] ?? null);
        $period = $this->periodFilter->resolve($arguments);

        try {
            $definition = $this->reportCatalog->findById($reportType);
            if ($definition === null || $definition->toolName !== $this->getName()) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось определить тип отчета.',
                ];
            }

            $report = $this->reportService->build($reportType, $organization, $user, [
                'date_from' => $period['date_from'],
                'date_to' => $period['date_to'],
                'project_id' => $arguments['project_id'] ?? null,
            ]);
            $report = $this->reportEnricher->enrich(
                $report,
                $this->composeRagReport($arguments, $organization, $user, $reportType, $definition->label, $period)
            );

            $pdf = Pdf::loadView('reports.operational-summary-pdf', [
                'report' => $report,
            ]);
            $pdf->setPaper('a4', 'portrait');
            $pdf->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

            $filename = $reportType.'_report_'.time().'.pdf';
            $path = $this->fileService->putContent($pdf->output(), 'reports', $filename, 'private', $organization);

            if (! is_string($path)) {
                throw new \RuntimeException('Не удалось сохранить отчет.');
            }

            $expiresAt = now()->addHours(24);
            $url = $this->fileService->temporaryUrl($path, 1440, $organization);

            if (! is_string($url) || $url === '') {
                throw new \RuntimeException('Не удалось сформировать ссылку на отчет.');
            }

            return [
                'status' => 'success',
                'message' => 'Отчет «'.$definition->label.'» сформирован.',
                'report_type' => $reportType,
                'period' => $period['period'],
                'pdf_url' => $url,
                'filename' => $filename,
                'storage_disk' => 's3',
                'storage_path' => $path,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        } catch (Throwable $throwable) {
            Log::error('AI Tool Error (GenerateOperationalPdfReportTool): '.$throwable->getMessage(), [
                'report_type' => $reportType,
                'organization_id' => $organization->id,
            ]);

            return [
                'status' => 'error',
                'message' => 'Не удалось сформировать отчет. Попробуйте повторить запрос или уточнить период.',
            ];
        }
    }

    private function normalizeReportType(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'projects_summary';
        }

        return Str::snake(trim($value));
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array{period: string, date_from: string|null, date_to: string|null, is_explicit: bool} $period
     * @return array<string, mixed>|null
     */
    private function composeRagReport(
        array $arguments,
        Organization $organization,
        ?User $user,
        string $reportType,
        string $label,
        array $period
    ): ?array {
        if (! $user instanceof User) {
            return null;
        }

        try {
            return $this->reportComposer->compose($organization, $user, array_filter([
                'report_type' => $reportType,
                'query' => $this->query($arguments, $label),
                'period' => $period['period'],
                'date_from' => $period['date_from'],
                'date_to' => $period['date_to'],
                'project_id' => is_numeric($arguments['project_id'] ?? null) ? (int) $arguments['project_id'] : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (Throwable $throwable) {
            Log::warning('AI operational report RAG enrichment failed: '.$throwable->getMessage(), [
                'report_type' => $reportType,
                'organization_id' => $organization->id,
                'exception_class' => $throwable::class,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function query(array $arguments, string $label): string
    {
        foreach (['query', 'topic', 'source_query'] as $key) {
            $value = $arguments[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return 'Отчет «'.$label.'»';
    }
}
