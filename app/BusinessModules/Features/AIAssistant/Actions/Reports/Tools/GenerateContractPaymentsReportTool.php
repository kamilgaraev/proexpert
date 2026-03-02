<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Organization;
use App\Models\User;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Services\Report\ReportService;

class GenerateContractPaymentsReportTool implements AIToolInterface
{
    use ReportDateHelper;

    protected ReportService $reportService;

    public function __construct(ReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    public function getName(): string
    {
        return 'generate_contract_payments_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF отчет по контрактам и платежам (суммы контрактов, сколько оплачено по каждому контракту, остатки и задолженности). Возвращает ссылку на скачивание (pdf_url).';
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
            'format' => 'pdf',
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
        ];

        if (isset($arguments['project_id'])) {
            $requestData['project_id'] = $arguments['project_id'];
        }

        $request = Request::create('/api/v1/admin/reports/contract-payments', 'GET', $requestData);
        $request->setUserResolver(fn() => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        try {
            /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
            $response = $this->reportService->getContractPaymentsReport($request);
            
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();
            
            $filename = 'contract_payments_report_' . time() . '.pdf';
            $path = "reports/{$organization->id}/{$filename}";
            
            Storage::disk('s3')->put($path, $content);
            $url = Storage::disk('s3')->temporaryUrl($path, now()->addHours(24));
            
            return [
                'status' => 'success',
                'message' => 'Отчет по контрактам и платежам успешно сгенерирован',
                'period' => $arguments['period'] ?? 'за этот месяц',
                'pdf_url' => $url,
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (GenerateContractPaymentsReportTool): ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return [
                'status' => 'error',
                'message' => 'Ошибка при генерации отчета: ' . $e->getMessage(),
            ];
        }
    }
}
