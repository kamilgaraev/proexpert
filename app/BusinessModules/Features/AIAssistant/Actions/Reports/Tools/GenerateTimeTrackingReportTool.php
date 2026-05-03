<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Organization;
use App\Models\User;
use App\Services\Report\ReportService;
use App\Services\Storage\OrganizationStoragePath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateTimeTrackingReportTool implements AIToolInterface
{
    use ReportDateHelper;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function getName(): string
    {
        return 'generate_time_tracking_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF отчет по учету рабочего времени сотрудников (табели). Показывает сколько часов отработано каждым сотрудником на проектах. Возвращает ссылку на скачивание (pdf_url).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'Текстовое описание периода (например: "за последний месяц", "за этот год", "сентябрь")',
                ],
                'date_from' => [
                    'type' => 'string',
                    'description' => 'Дата начала периода в формате YYYY-MM-DD. Если указана вместе с date_to, используется вместо текстового period.',
                ],
                'date_to' => [
                    'type' => 'string',
                    'description' => 'Дата окончания периода в формате YYYY-MM-DD. Если указана вместе с date_from, используется вместо текстового period.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'description' => 'ID сотрудника (необязательно)',
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта (необязательно)',
                ],
            ],
            'required' => ['period'],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $period = (string) ($arguments['period'] ?? 'за этот месяц');
        $dates = $this->extractPeriodFromArguments($arguments, $period);

        $requestData = [
            'format' => 'pdf',
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
        ];

        if (isset($arguments['user_id'])) {
            $requestData['user_id'] = $arguments['user_id'];
        }
        if (isset($arguments['project_id'])) {
            $requestData['project_id'] = $arguments['project_id'];
        }

        $request = Request::create('/api/v1/admin/reports/time-tracking', 'GET', $requestData);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        try {
            // getTimeTrackingReport иногда возвращает $this->pdfExporter->download, а иногда streamDownload,
            // но обычно это StreamedResponse если возвращается download()
            $response = $this->reportService->getTimeTrackingReport($request);

            if ($response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
                ob_start();
                $response->sendContent();
                $content = ob_get_clean();
            } elseif ($response instanceof \Illuminate\Http\Response) {
                // Если это обычный Response с контентом
                $content = $response->getContent();
            } else {
                throw new \Exception('Неожиданный формат ответа от ReportService (не StreamedResponse)');
            }

            $filename = 'time_tracking_report_'.time().'.pdf';
            $path = OrganizationStoragePath::forOrganization($organization->id, "reports/{$filename}");

            if (Storage::disk('s3')->put($path, $content) !== true) {
                throw new \RuntimeException('Не удалось сохранить отчет в S3.');
            }
            $expiresAt = now()->addHours(24);
            $url = Storage::disk('s3')->temporaryUrl($path, $expiresAt);

            return [
                'status' => 'success',
                'message' => 'Отчет по учету времени успешно сгенерирован',
                'period' => $period,
                'pdf_url' => $url,
                'filename' => $filename,
                'storage_disk' => 's3',
                'storage_path' => $path,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (GenerateTimeTrackingReportTool): '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return [
                'status' => 'error',
                'message' => 'Ошибка при генерации отчета: '.$e->getMessage(),
            ];
        }
    }
}
