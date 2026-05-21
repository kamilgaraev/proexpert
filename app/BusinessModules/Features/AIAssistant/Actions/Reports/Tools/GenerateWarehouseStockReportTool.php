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

class GenerateWarehouseStockReportTool implements AIToolInterface
{
    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function getName(): string
    {
        return 'generate_warehouse_stock_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF отчет об остатках на складах (какие материалы есть в наличии, их количество и стоимость). Возвращает ссылку на скачивание (pdf_url). Период для этого отчета не требуется.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'warehouse_id' => [
                    'type' => 'integer',
                    'description' => 'ID склада (необязательно)',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $requestData = [
            'format' => 'pdf',
        ];

        if (isset($arguments['warehouse_id'])) {
            $requestData['warehouse_id'] = $arguments['warehouse_id'];
        }

        $request = Request::create('/api/v1/admin/reports/warehouse-stock', 'GET', $requestData);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        try {
            /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
            $response = $this->reportService->getWarehouseStockReport($request);

            ob_start();
            $response->sendContent();
            $content = ob_get_clean();

            $filename = 'warehouse_stock_report_'.time().'.pdf';
            $path = OrganizationStoragePath::forOrganization($organization->id, "reports/{$filename}");

            if (Storage::disk('s3')->put($path, $content) !== true) {
                throw new \RuntimeException('Не удалось сохранить отчет в S3.');
            }
            $expiresAt = now()->addHours(24);
            $url = Storage::disk('s3')->temporaryUrl($path, $expiresAt);

            return [
                'status' => 'success',
                'message' => 'Отчет по остаткам на складах успешно сгенерирован',
                'pdf_url' => $url,
                'filename' => $filename,
                'storage_disk' => 's3',
                'storage_path' => $path,
                'expires_at' => $expiresAt->toIso8601String(),
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (GenerateWarehouseStockReportTool): '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return [
                'status' => 'error',
                'message' => 'Ошибка при генерации отчета: '.$e->getMessage(),
            ];
        }
    }
}
