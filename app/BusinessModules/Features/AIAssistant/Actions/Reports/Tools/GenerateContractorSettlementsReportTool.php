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

class GenerateContractorSettlementsReportTool implements AIToolInterface
{
    use ReportDateHelper;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function getName(): string
    {
        return 'generate_contractor_settlements_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF отчет по взаиморасчетам с подрядчиками (сколько выполнено работ, сколько оплачено, какая задолженность перед подрядчиком). Возвращает ссылку на скачивание (pdf_url).';
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
                'contractor_id' => [
                    'type' => 'integer',
                    'description' => 'ID подрядчика (необязательно)',
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

        if (isset($arguments['contractor_id'])) {
            $requestData['contractor_id'] = $arguments['contractor_id'];
        }

        $request = Request::create('/api/v1/admin/reports/contractor-settlements', 'GET', $requestData);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        try {
            /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
            $response = $this->reportService->getContractorSettlementsReport($request);

            ob_start();
            $response->sendContent();
            $content = ob_get_clean();

            $filename = 'contractor_settlements_report_'.time().'.pdf';
            $path = OrganizationStoragePath::forOrganization($organization->id, "reports/{$filename}");

            if (Storage::disk('s3')->put($path, $content) !== true) {
                throw new \RuntimeException('Не удалось сохранить отчет в S3.');
            }
            $expiresAt = now()->addHours(24);
            $url = Storage::disk('s3')->temporaryUrl($path, $expiresAt);

            return [
                'status' => 'success',
                'message' => 'Отчет по взаиморасчетам с подрядчиками успешно сгенерирован',
                'period' => $period,
                'pdf_url' => $url,
                'filename' => $filename,
                'storage_disk' => 's3',
                'storage_path' => $path,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (GenerateContractorSettlementsReportTool): '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return [
                'status' => 'error',
                'message' => 'Ошибка при генерации отчета: '.$e->getMessage(),
            ];
        }
    }
}
