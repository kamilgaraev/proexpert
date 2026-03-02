<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Organization;
use App\Models\User;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Services\Report\ReportService;

class GenerateMaterialMovementsReportTool implements AIToolInterface
{
    use ReportDateHelper;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function getName(): string
    {
        return 'generate_material_movements_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует Excel отчет по движению и расходам материалов. Возвращает ссылку на скачивание (excel_url). Этот отчет создается в формате Excel (xlsx), так как PDF для него не поддерживается.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'period' => [
                    'type' => 'string',
                    'description' => 'Текстовое описание периода (например: "за последний месяц", "за этот год", "сентябрь")'
                ],
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта (необязательно)'
                ]
            ],
            'required' => ['period']
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $dates = $this->extractPeriod($arguments['period'] ?? 'за этот месяц');
        
        $requestData = [
            'format' => 'excel',
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
        ];

        if (isset($arguments['project_id'])) {
            $requestData['project_id'] = $arguments['project_id'];
        }

        $request = Request::create('/api/v1/admin/reports/material-movements', 'GET', $requestData);
        $request->setUserResolver(fn() => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        try {
            /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
            $response = $this->reportService->getMaterialMovementsReport($request);
            
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
            
            $filename = 'material_movements_report_' . time() . '.xlsx';
            $path = "reports/{$organization->id}/{$filename}";
            
            Storage::disk('s3')->put($path, $content);
            $url = Storage::disk('s3')->temporaryUrl($path, now()->addHours(24));
            
            return [
                'status' => 'success',
                'message' => 'Отчет по движению материалов успешно сгенерирован',
                'period' => $arguments['period'] ?? 'за этот месяц',
                'excel_url' => $url,
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (GenerateMaterialMovementsReportTool): ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'status' => 'error',
                'message' => 'Ошибка при генерации отчета: ' . $e->getMessage(),
            ];
        }
    }
}
