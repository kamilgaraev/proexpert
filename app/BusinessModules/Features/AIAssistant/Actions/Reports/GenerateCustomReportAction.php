<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\Storage\FileService;
use Aws\S3\S3Client;

class GenerateCustomReportAction
{
    public function execute(int $organizationId, ?array $params = []): array
    {
        $reportType = $this->detectReportType($params['query'] ?? '');
        $period = $this->extractPeriod($params['query'] ?? '');
        
        $data = match($reportType) {
            'materials_expenses' => $this->getMaterialsExpensesReport($organizationId, $period, $params),
            'contractor_payments' => $this->getContractorPaymentsReport($organizationId, $period, $params),
            'project_financials' => $this->getProjectFinancialsReport($organizationId, $period, $params),
            'completed_works' => $this->getCompletedWorksReport($organizationId, $period, $params),
            'contracts_summary' => $this->getContractsSummaryReport($organizationId, $period, $params),
            default => $this->getGeneralFinancialReport($organizationId, $period, $params),
        };
        
        // Генерируем PDF
        $pdfUrl = $this->generatePDF($data, $organizationId);
        $data['pdf_url'] = $pdfUrl;
        $data['pdf_generated'] = true;
        
        return $data;
    }
    
    protected function detectReportType(string $query): string
    {
        $query = mb_strtolower($query);
        
        if (preg_match('/(материал|затрат.* на материал|расход.* материал)/ui', $query)) {
            return 'materials_expenses';
        }
        if (preg_match('/(подрядчик|оплат.* подрядчик|выплат)/ui', $query)) {
            return 'contractor_payments';
        }
        if (preg_match('/(выполнен.* работ|акт|КС-2)/ui', $query)) {
            return 'completed_works';
        }
        if (preg_match('/(контракт|договор)/ui', $query)) {
            return 'contracts_summary';
        }
        if (preg_match('/(проект|финанс.* проект)/ui', $query)) {
            return 'project_financials';
        }
        
        return 'general';
    }
    
    protected function extractPeriod(string $query): array
    {
        $query = mb_strtolower($query);
        $now = Carbon::now();
        
        // Последний месяц
        if (preg_match('/(последн|прошл).* месяц/ui', $query)) {
            return [
                'start' => $now->copy()->subMonth()->startOfMonth(),
                'end' => $now->copy()->subMonth()->endOfMonth(),
                'label' => 'За последний месяц',
            ];
        }
        
        // Этот месяц
        if (preg_match('/(этот|текущ|за).* месяц/ui', $query)) {
            return [
                'start' => $now->copy()->startOfMonth(),
                'end' => $now->copy()->endOfMonth(),
                'label' => 'За текущий месяц',
            ];
        }
        
        // Квартал
        if (preg_match('/(квартал|кварт)/ui', $query)) {
            return [
                'start' => $now->copy()->startOfQuarter(),
                'end' => $now->copy()->endOfQuarter(),
                'label' => 'За текущий квартал',
            ];
        }
        
        // Год
        if (preg_match('/(год|за год|в год)/ui', $query)) {
            return [
                'start' => $now->copy()->startOfYear(),
                'end' => $now->copy()->endOfYear(),
                'label' => 'За текущий год',
            ];
        }
        
        // Конкретный месяц (сентябрь, октябрь и т.д.)
        $months = [
            'январ' => 1, 'феврал' => 2, 'март' => 3, 'апрел' => 4, 'ма[йя]' => 5, 'июн' => 6,
            'июл' => 7, 'август' => 8, 'сентябр' => 9, 'октябр' => 10, 'ноябр' => 11, 'декабр' => 12,
        ];
        
        foreach ($months as $pattern => $monthNum) {
            if (preg_match("/$pattern/ui", $query)) {
                $date = Carbon::create($now->year, $monthNum, 1);
                return [
                    'start' => $date->copy()->startOfMonth(),
                    'end' => $date->copy()->endOfMonth(),
                    'label' => 'За ' . $date->locale('ru')->monthName,
                ];
            }
        }
        
        // По умолчанию - за последние 30 дней
        return [
            'start' => $now->copy()->subDays(30),
            'end' => $now,
            'label' => 'За последние 30 дней',
        ];
    }
    
    protected function getMaterialsExpensesReport(int $organizationId, array $period, array $params): array
    {
        $projectId = $params['project_id'] ?? null;
        
        $query = DB::table('material_write_offs')
            ->join('materials', 'material_write_offs.material_id', '=', 'materials.id')
            ->join('measurement_units', 'materials.measurement_unit_id', '=', 'measurement_units.id')
            ->leftJoin('projects', 'material_write_offs.project_id', '=', 'projects.id')
            ->where('material_write_offs.organization_id', $organizationId)
            ->whereBetween('material_write_offs.write_off_date', [$period['start'], $period['end']])
            ->whereNull('material_write_offs.deleted_at');
        
        if ($projectId) {
            $query->where('material_write_offs.project_id', $projectId);
        }
        
        $data = $query->select(
                'materials.name as material_name',
                'materials.default_price',
                'measurement_units.name as unit',
                'projects.name as project_name',
                DB::raw('SUM(material_write_offs.quantity) as total_quantity'),
                DB::raw('SUM(material_write_offs.quantity * COALESCE(materials.default_price, 0)) as total_amount')
            )
            ->groupBy('materials.id', 'materials.name', 'materials.default_price', 'measurement_units.name', 'projects.id', 'projects.name')
            ->orderByDesc('total_amount')
            ->get();
        
        $totalAmount = $data->sum('total_amount');
        
        return [
            'report_type' => 'materials_expenses',
            'period' => $period['label'],
            'period_start' => $period['start']->format('Y-m-d'),
            'period_end' => $period['end']->format('Y-m-d'),
            'total_amount' => $totalAmount,
            'items_count' => count($data),
            'items' => $data->map(function($item) {
                return [
                    'material' => $item->material_name,
                    'project' => $item->project_name ?? 'Не привязано к проекту',
                    'quantity' => (float)$item->total_quantity,
                    'unit' => $item->unit,
                    'amount' => (float)$item->total_amount,
                ];
            })->toArray(),
        ];
    }
    
    protected function getContractorPaymentsReport(int $organizationId, array $period, array $params): array
    {
        // Используем новую таблицу invoices вместо contract_payments
        $data = DB::table('invoices')
            ->join('contracts', function($join) {
                $join->on('invoices.invoiceable_id', '=', 'contracts.id')
                     ->where('invoices.invoiceable_type', '=', 'App\\Models\\Contract');
            })
            ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
            ->where('contracts.organization_id', $organizationId)
            ->whereBetween('invoices.paid_at', [$period['start'], $period['end']])
            ->whereNull('contracts.deleted_at')
            ->whereNull('invoices.deleted_at')
            ->whereNotNull('invoices.paid_at')
            ->select(
                'contractors.name as contractor_name',
                'contracts.number as contract_number',
                'projects.name as project_name',
                DB::raw('SUM(invoices.paid_amount) as total_paid'),
                DB::raw('COUNT(invoices.id) as payments_count')
            )
            ->groupBy('contractors.id', 'contractors.name', 'contracts.id', 'contracts.number', 'projects.id', 'projects.name')
            ->orderByDesc('total_paid')
            ->get();
        
        $totalPaid = $data->sum('total_paid');
        
        return [
            'report_type' => 'contractor_payments',
            'period' => $period['label'],
            'period_start' => $period['start']->format('Y-m-d'),
            'period_end' => $period['end']->format('Y-m-d'),
            'total_paid' => $totalPaid,
            'contractors_count' => $data->unique('contractor_name')->count(),
            'payments_count' => $data->sum('payments_count'),
            'items' => $data->map(function($item) {
                return [
                    'contractor' => $item->contractor_name,
                    'contract' => $item->contract_number,
                    'project' => $item->project_name ?? 'Не привязан',
                    'total_paid' => (float)$item->total_paid,
                    'payments_count' => (int)$item->payments_count,
                ];
            })->toArray(),
        ];
    }
    
    protected function getProjectFinancialsReport(int $organizationId, array $period, array $params): array
    {
        $projectId = $params['project_id'] ?? null;
        
        $query = DB::table('projects')
            ->leftJoin('completed_works', function($join) use ($period) {
                $join->on('projects.id', '=', 'completed_works.project_id')
                    ->where('completed_works.status', '=', 'confirmed')
                    ->whereBetween('completed_works.completion_date', [$period['start'], $period['end']])
                    ->whereNull('completed_works.deleted_at');
            })
            ->where('projects.organization_id', $organizationId)
            ->whereNull('projects.deleted_at');
        
        if ($projectId) {
            $query->where('projects.id', $projectId);
        }
        
        $data = $query->select(
                'projects.id',
                'projects.name',
                'projects.budget_amount',
                'projects.status',
                DB::raw('COALESCE(SUM(completed_works.total_amount), 0) as spent_in_period'),
                DB::raw('COUNT(completed_works.id) as works_count')
            )
            ->groupBy('projects.id', 'projects.name', 'projects.budget_amount', 'projects.status')
            ->orderByDesc('spent_in_period')
            ->get();
        
        $totalSpent = $data->sum('spent_in_period');
        
        return [
            'report_type' => 'project_financials',
            'period' => $period['label'],
            'period_start' => $period['start']->format('Y-m-d'),
            'period_end' => $period['end']->format('Y-m-d'),
            'total_spent' => $totalSpent,
            'projects_count' => count($data),
            'items' => $data->map(function($item) {
                return [
                    'project' => $item->name,
                    'status' => $item->status,
                    'budget' => (float)($item->budget_amount ?? 0),
                    'spent_in_period' => (float)$item->spent_in_period,
                    'works_count' => (int)$item->works_count,
                ];
            })->toArray(),
        ];
    }
    
    protected function getCompletedWorksReport(int $organizationId, array $period, array $params): array
    {
        $projectId = $params['project_id'] ?? null;
        
        $query = DB::table('completed_works')
            ->join('work_types', 'completed_works.work_type_id', '=', 'work_types.id')
            ->join('projects', 'completed_works.project_id', '=', 'projects.id')
            ->leftJoin('contracts', 'completed_works.contract_id', '=', 'contracts.id')
            ->where('completed_works.organization_id', $organizationId)
            ->where('completed_works.status', 'confirmed')
            ->whereBetween('completed_works.completion_date', [$period['start'], $period['end']])
            ->whereNull('completed_works.deleted_at');
        
        if ($projectId) {
            $query->where('completed_works.project_id', $projectId);
        }
        
        $data = $query->select(
                'work_types.name as work_type',
                'projects.name as project_name',
                'contracts.number as contract_number',
                DB::raw('SUM(completed_works.quantity) as total_quantity'),
                DB::raw('SUM(completed_works.total_amount) as total_amount'),
                DB::raw('COUNT(completed_works.id) as records_count')
            )
            ->groupBy('work_types.id', 'work_types.name', 'projects.id', 'projects.name', 'contracts.id', 'contracts.number')
            ->orderByDesc('total_amount')
            ->get();
        
        $totalAmount = $data->sum('total_amount');
        
        return [
            'report_type' => 'completed_works',
            'period' => $period['label'],
            'period_start' => $period['start']->format('Y-m-d'),
            'period_end' => $period['end']->format('Y-m-d'),
            'total_amount' => $totalAmount,
            'works_count' => $data->sum('records_count'),
            'items' => $data->map(function($item) {
                return [
                    'work_type' => $item->work_type,
                    'project' => $item->project_name,
                    'contract' => $item->contract_number ?? 'Без контракта',
                    'quantity' => (float)$item->total_quantity,
                    'amount' => (float)$item->total_amount,
                    'records' => (int)$item->records_count,
                ];
            })->toArray(),
        ];
    }
    
    protected function getContractsSummaryReport(int $organizationId, array $period, array $params): array
    {
        $data = DB::table('contracts')
            ->join('contractors', 'contracts.contractor_id', '=', 'contractors.id')
            ->leftJoin('projects', 'contracts.project_id', '=', 'projects.id')
            ->where('contracts.organization_id', $organizationId)
            ->whereBetween('contracts.date', [$period['start'], $period['end']])
            ->whereNull('contracts.deleted_at')
            ->select(
                'contracts.number',
                'contracts.date',
                'contracts.status',
                'contracts.total_amount',
                'contractors.name as contractor_name',
                'projects.name as project_name'
            )
            ->orderByDesc('contracts.date')
            ->get();
        
        $totalAmount = $data->sum('total_amount');
        $byStatus = $data->groupBy('status')->map(fn($g) => [
            'count' => count($g),
            'amount' => $g->sum('total_amount'),
        ])->toArray();
        
        return [
            'report_type' => 'contracts_summary',
            'period' => $period['label'],
            'period_start' => $period['start']->format('Y-m-d'),
            'period_end' => $period['end']->format('Y-m-d'),
            'total_amount' => $totalAmount,
            'contracts_count' => count($data),
            'by_status' => $byStatus,
            'items' => $data->map(function($item) {
                return [
                    'number' => $item->number,
                    'date' => $item->date,
                    'status' => $item->status,
                    'amount' => (float)$item->total_amount,
                    'contractor' => $item->contractor_name,
                    'project' => $item->project_name ?? 'Не привязан',
                ];
            })->toArray(),
        ];
    }
    
    protected function getGeneralFinancialReport(int $organizationId, array $period, array $params): array
    {
        // Общий финансовый отчет - доходы/расходы
        $materials = DB::table('material_write_offs')
            ->join('materials', 'material_write_offs.material_id', '=', 'materials.id')
            ->where('material_write_offs.organization_id', $organizationId)
            ->whereBetween('material_write_offs.write_off_date', [$period['start'], $period['end']])
            ->whereNull('material_write_offs.deleted_at')
            ->sum(DB::raw('material_write_offs.quantity * COALESCE(materials.default_price, 0)'));
        
        $works = DB::table('completed_works')
            ->where('organization_id', $organizationId)
            ->where('status', 'confirmed')
            ->whereBetween('completion_date', [$period['start'], $period['end']])
            ->whereNull('deleted_at')
            ->sum('total_amount');
        
        // Используем новую таблицу invoices вместо contract_payments
        $payments = DB::table('invoices')
            ->join('contracts', function($join) {
                $join->on('invoices.invoiceable_id', '=', 'contracts.id')
                     ->where('invoices.invoiceable_type', '=', 'App\\Models\\Contract');
            })
            ->where('contracts.organization_id', $organizationId)
            ->whereBetween('invoices.paid_at', [$period['start'], $period['end']])
            ->whereNull('contracts.deleted_at')
            ->whereNull('invoices.deleted_at')
            ->whereNotNull('invoices.paid_at')
            ->sum('invoices.paid_amount');
        
        return [
            'report_type' => 'general_financial',
            'period' => $period['label'],
            'period_start' => $period['start']->format('Y-m-d'),
            'period_end' => $period['end']->format('Y-m-d'),
            'summary' => [
                'completed_works' => (float)$works,
                'materials_expenses' => (float)$materials,
                'contractor_payments' => (float)$payments,
                'net_result' => (float)($works - $materials - $payments),
            ],
        ];
    }
    
    protected function generatePDF(array $data, int $organizationId): string
    {
        $fileName = 'report_' . $data['report_type'] . '_' . time() . '.pdf';
        
        // Генерируем HTML для PDF
        $html = $this->generateReportHTML($data);
        
        // Создаем PDF
        $pdf = Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10)
            ->setOption('margin-right', 10);
        
        // Получаем PDF как строку
        $pdfContent = $pdf->output();
        
        // Формируем путь в S3 с иерархической структурой
        $reportTypeFolder = match($data['report_type']) {
            'materials_expenses' => 'materials',
            'contractor_payments' => 'contractors',
            'project_financials' => 'projects',
            'completed_works' => 'works',
            'contracts_summary' => 'contracts',
            'general_financial' => 'general',
            default => 'other'
        };

        $s3Path = "org-{$organizationId}/ai-reports/{$reportTypeFolder}/{$fileName}";

        // Сохраняем в S3
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('s3');
        $disk->put($s3Path, $pdfContent);

        // Генерируем presigned URL для Yandex Cloud Storage
        return $this->generatePresignedUrl($s3Path);
    }
    
    protected function generateReportHTML(array $data): string
    {
        $reportType = $data['report_type'];
        $period = $data['period'] ?? '';
        
        $html = '<html><head><meta charset="UTF-8"><style>';
        $html .= 'body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; }';
        $html .= 'h1 { text-align: center; color: #333; font-size: 18pt; margin-bottom: 5px; }';
        $html .= 'h2 { text-align: center; color: #666; font-size: 12pt; margin-top: 0; margin-bottom: 20px; }';
        $html .= 'table { width: 100%; border-collapse: collapse; margin-top: 15px; }';
        $html .= 'th { background-color: #4CAF50; color: white; padding: 8px; text-align: left; font-size: 9pt; }';
        $html .= 'td { padding: 6px; border-bottom: 1px solid #ddd; font-size: 9pt; }';
        $html .= 'tr:hover { background-color: #f5f5f5; }';
        $html .= '.summary { background-color: #f0f0f0; padding: 10px; margin: 15px 0; border-left: 4px solid #4CAF50; }';
        $html .= '.summary-item { margin: 5px 0; font-size: 11pt; }';
        $html .= '.label { font-weight: bold; }';
        $html .= '.total { font-weight: bold; background-color: #e8f5e9; }';
        $html .= '.footer { margin-top: 20px; text-align: center; font-size: 8pt; color: #999; }';
        $html .= '</style></head><body>';
        
        // Заголовок
        $titles = [
            'materials_expenses' => 'Отчет по расходам материалов',
            'contractor_payments' => 'Отчет по выплатам подрядчикам',
            'project_financials' => 'Финансовый отчет по проектам',
            'completed_works' => 'Отчет по выполненным работам',
            'contracts_summary' => 'Сводка по контрактам',
            'general_financial' => 'Общий финансовый отчет',
        ];
        
        $html .= '<h1>' . ($titles[$reportType] ?? 'Отчет') . '</h1>';
        $html .= '<h2>' . $period . '</h2>';
        
        // Генерируем контент в зависимости от типа отчета
        $html .= match($reportType) {
            'materials_expenses' => $this->generateMaterialsExpensesHTML($data),
            'contractor_payments' => $this->generateContractorPaymentsHTML($data),
            'project_financials' => $this->generateProjectFinancialsHTML($data),
            'completed_works' => $this->generateCompletedWorksHTML($data),
            'contracts_summary' => $this->generateContractsSummaryHTML($data),
            'general_financial' => $this->generateGeneralFinancialHTML($data),
            default => '',
        };
        
        // Футер
        $html .= '<div class="footer">Сгенерировано ' . Carbon::now()->format('d.m.Y H:i') . '</div>';
        $html .= '</body></html>';
        
        return $html;
    }
    
    protected function generateMaterialsExpensesHTML(array $data): string
    {
        $html = '<div class="summary">';
        $html .= '<div class="summary-item"><span class="label">Общая сумма:</span> ' . number_format($data['total_amount'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Позиций:</span> ' . $data['items_count'] . '</div>';
        $html .= '</div>';
        
        $html .= '<table>';
        $html .= '<tr><th>Материал</th><th>Проект</th><th>Количество</th><th>Ед. изм.</th><th>Сумма, руб.</th></tr>';
        
        foreach ($data['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['material']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['project']) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['quantity'], 2, ',', ' ') . '</td>';
            $html .= '<td>' . htmlspecialchars($item['unit']) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['amount'], 2, ',', ' ') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '<tr class="total">';
        $html .= '<td colspan="4" style="text-align: right;">ИТОГО:</td>';
        $html .= '<td style="text-align: right;">' . number_format($data['total_amount'], 2, ',', ' ') . '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        return $html;
    }
    
    protected function generateContractorPaymentsHTML(array $data): string
    {
        $html = '<div class="summary">';
        $html .= '<div class="summary-item"><span class="label">Общая сумма выплат:</span> ' . number_format($data['total_paid'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Подрядчиков:</span> ' . $data['contractors_count'] . '</div>';
        $html .= '<div class="summary-item"><span class="label">Платежей:</span> ' . $data['payments_count'] . '</div>';
        $html .= '</div>';
        
        $html .= '<table>';
        $html .= '<tr><th>Подрядчик</th><th>Контракт</th><th>Проект</th><th>Платежей</th><th>Сумма, руб.</th></tr>';
        
        foreach ($data['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['contractor']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['contract']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['project']) . '</td>';
            $html .= '<td style="text-align: center;">' . $item['payments_count'] . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['total_paid'], 2, ',', ' ') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '<tr class="total">';
        $html .= '<td colspan="4" style="text-align: right;">ИТОГО:</td>';
        $html .= '<td style="text-align: right;">' . number_format($data['total_paid'], 2, ',', ' ') . '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        return $html;
    }
    
    protected function generateProjectFinancialsHTML(array $data): string
    {
        $html = '<div class="summary">';
        $html .= '<div class="summary-item"><span class="label">Потрачено за период:</span> ' . number_format($data['total_spent'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Проектов:</span> ' . $data['projects_count'] . '</div>';
        $html .= '</div>';
        
        $html .= '<table>';
        $html .= '<tr><th>Проект</th><th>Статус</th><th>Бюджет, руб.</th><th>Потрачено, руб.</th><th>Работ</th></tr>';
        
        foreach ($data['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['project']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['status']) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['budget'], 2, ',', ' ') . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['spent_in_period'], 2, ',', ' ') . '</td>';
            $html .= '<td style="text-align: center;">' . $item['works_count'] . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '<tr class="total">';
        $html .= '<td colspan="3" style="text-align: right;">ИТОГО потрачено:</td>';
        $html .= '<td style="text-align: right;">' . number_format($data['total_spent'], 2, ',', ' ') . '</td>';
        $html .= '<td></td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        return $html;
    }
    
    protected function generateCompletedWorksHTML(array $data): string
    {
        $html = '<div class="summary">';
        $html .= '<div class="summary-item"><span class="label">Общая сумма работ:</span> ' . number_format($data['total_amount'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Записей:</span> ' . $data['works_count'] . '</div>';
        $html .= '</div>';
        
        $html .= '<table>';
        $html .= '<tr><th>Вид работ</th><th>Проект</th><th>Контракт</th><th>Объем</th><th>Сумма, руб.</th></tr>';
        
        foreach ($data['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['work_type']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['project']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['contract']) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['quantity'], 2, ',', ' ') . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['amount'], 2, ',', ' ') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '<tr class="total">';
        $html .= '<td colspan="4" style="text-align: right;">ИТОГО:</td>';
        $html .= '<td style="text-align: right;">' . number_format($data['total_amount'], 2, ',', ' ') . '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        return $html;
    }
    
    protected function generateContractsSummaryHTML(array $data): string
    {
        $html = '<div class="summary">';
        $html .= '<div class="summary-item"><span class="label">Общая сумма контрактов:</span> ' . number_format($data['total_amount'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Контрактов:</span> ' . $data['contracts_count'] . '</div>';
        $html .= '</div>';
        
        $html .= '<table>';
        $html .= '<tr><th>Номер</th><th>Дата</th><th>Статус</th><th>Подрядчик</th><th>Проект</th><th>Сумма, руб.</th></tr>';
        
        foreach ($data['items'] as $item) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($item['number']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['date']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['status']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['contractor']) . '</td>';
            $html .= '<td>' . htmlspecialchars($item['project']) . '</td>';
            $html .= '<td style="text-align: right;">' . number_format($item['amount'], 2, ',', ' ') . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '<tr class="total">';
        $html .= '<td colspan="5" style="text-align: right;">ИТОГО:</td>';
        $html .= '<td style="text-align: right;">' . number_format($data['total_amount'], 2, ',', ' ') . '</td>';
        $html .= '</tr>';
        $html .= '</table>';
        
        return $html;
    }
    
    protected function generateGeneralFinancialHTML(array $data): string
    {
        $summary = $data['summary'];
        
        $html = '<div class="summary">';
        $html .= '<h3 style="margin-top: 0;">Финансовая сводка</h3>';
        $html .= '<div class="summary-item"><span class="label">Выполнено работ:</span> ' . number_format($summary['completed_works'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Расходы на материалы:</span> ' . number_format($summary['materials_expenses'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item"><span class="label">Выплаты подрядчикам:</span> ' . number_format($summary['contractor_payments'], 2, ',', ' ') . ' руб.</div>';
        $html .= '<div class="summary-item" style="font-size: 12pt; margin-top: 10px; padding-top: 10px; border-top: 2px solid #4CAF50;">';
        $html .= '<span class="label">Чистый результат:</span> <strong>' . number_format($summary['net_result'], 2, ',', ' ') . ' руб.</strong>';
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }


    /**
     * Генерирует presigned URL для Yandex Cloud Storage
     */
    protected function generatePresignedUrl(string $s3Path): string
    {
        try {
            $config = config('filesystems.disks.s3');
            $bucket = $config['bucket'] ?? 'prohelper-storage';
            
            $s3Client = new S3Client([
                'version' => 'latest',
                'region' => $config['region'] ?? 'ru-central1',
                'endpoint' => 'https://storage.yandexcloud.net',
                'use_path_style_endpoint' => false,
                'credentials' => [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ],
            ]);

            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key' => $s3Path,
            ]);

            $request = $s3Client->createPresignedRequest($cmd, '+24 hours');
            $presignedUrl = (string) $request->getUri();

            Log::debug('AI Report presigned URL generated', [
                'path' => $s3Path,
                'url' => $presignedUrl,
                'bucket' => $bucket,
                'expires_at' => now()->addDay()->toISOString(),
            ]);

            return $presignedUrl;

        } catch (\Exception $e) {
            Log::error('AI Report presigned URL failed', [
                'path' => $s3Path,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new \RuntimeException('Не удалось сгенерировать ссылку на отчет: ' . $e->getMessage());
        }
    }
}

