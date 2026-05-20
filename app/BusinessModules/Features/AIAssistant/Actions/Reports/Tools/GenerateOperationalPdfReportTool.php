<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantOperationalReportService;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportCatalog;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\OrganizationStoragePath;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class GenerateOperationalPdfReportTool implements AIToolInterface
{
    use ReportDateHelper;

    public function __construct(
        private readonly AssistantOperationalReportService $reportService,
        private readonly AssistantReportCatalog $reportCatalog
    ) {}

    public function getName(): string
    {
        return 'generate_operational_pdf_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF для дополнительных операционных отчетов ProHelper: проекты, закупки, заказы поставщикам, предложения поставщиков, заявки со стройплощадки, сметы, качество, безопасность, техника и посещаемость.';
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
        $period = (string) ($arguments['period'] ?? 'за этот месяц');
        $dates = $this->extractPeriodFromArguments($arguments, $period);

        try {
            $definition = $this->reportCatalog->findById($reportType);
            if ($definition === null || $definition->toolName !== $this->getName()) {
                return [
                    'status' => 'error',
                    'message' => 'Не удалось определить тип отчета.',
                ];
            }

            $report = $this->reportService->build($reportType, $organization, $user, [
                'date_from' => $dates['date_from'],
                'date_to' => $dates['date_to'],
                'project_id' => $arguments['project_id'] ?? null,
            ]);

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
            $path = OrganizationStoragePath::forOrganization($organization->id, "reports/{$filename}");

            if (Storage::disk('s3')->put($path, $pdf->output()) !== true) {
                throw new \RuntimeException('Не удалось сохранить отчет.');
            }

            $expiresAt = now()->addHours(24);
            $url = Storage::disk('s3')->temporaryUrl($path, $expiresAt);

            return [
                'status' => 'success',
                'message' => 'Отчет «'.$definition->label.'» сформирован.',
                'report_type' => $reportType,
                'period' => $period,
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
}
