<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantGeneratedReportStorage;
use App\Models\Organization;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GenerateProfitabilityReportTool implements AIToolInterface
{
    use ReportDateHelper;

    public function __construct(
        protected ReportService $reportService,
        private readonly AssistantGeneratedReportStorage $reportStorage,
    ) {}

    public function getName(): string
    {
        return 'generate_profitability_report';
    }

    public function getDescription(): string
    {
        return 'Генерирует PDF отчет по рентабельности и доходности строительных проектов (сравнение доходов и расходов). Возвращает ссылку на скачивание (pdf_url). Использовать, когда спрашивают про прибыль, рентабельность, убытки или доходы проектов.';
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
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'ID проекта, если нужно посмотреть рентабельность конкретного проекта (необязательно)',
                ],
            ],
            'required' => ['period'],
        ];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        if (! $user instanceof User) {
            return ['status' => 'error', 'message' => trans_message('errors.unauthenticated')];
        }

        $period = (string) ($arguments['period'] ?? 'за этот месяц');
        $dates = $this->extractPeriodFromArguments($arguments, $period);

        $requestData = [
            'format' => 'pdf',
            'date_from' => $dates['date_from'],
            'date_to' => $dates['date_to'],
        ];

        if (isset($arguments['project_id'])) {
            $requestData['project_id'] = $arguments['project_id'];
        }

        $request = Request::create('/api/v1/admin/reports/project-profitability', 'GET', $requestData);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('current_organization_id', $organization->id);

        try {
            /** @var \Symfony\Component\HttpFoundation\StreamedResponse $response */
            $response = $this->reportService->getProjectProfitabilityReport($request);

            // Захватываем сгенерированный PDF контент из StreamedResponse
            ob_start();
            $response->sendContent();
            $content = ob_get_clean();

            $filename = 'project_profitability_report_'.time().'.pdf';
            $stored = $this->reportStorage->storePdf((string) $content, $filename, $organization, $user);

            return [
                'status' => 'success',
                'message' => 'Отчет о рентабельности успешно сгенерирован',
                'period' => $period,
                ...$stored,
            ];
        } catch (\Exception $e) {
            Log::error('AI Tool Error (GenerateProfitabilityReportTool): '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return [
                'status' => 'error',
                'message' => 'Ошибка при генерации отчета: '.$e->getMessage(),
            ];
        }
    }
}
