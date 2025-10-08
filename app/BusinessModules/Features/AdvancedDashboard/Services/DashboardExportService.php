<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Services\LogService;
use App\BusinessModules\Features\AdvancedDashboard\Models\Dashboard;
use App\BusinessModules\Features\AdvancedDashboard\Models\ScheduledReport;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\WidgetService;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Сервис экспорта дашбордов
 * 
 * Предоставляет методы для:
 * - Экспорта дашборда в PDF
 * - Экспорта дашборда в Excel
 * - Генерации scheduled reports
 * - Отправки отчетов по email
 */
class DashboardExportService
{
    protected WidgetService $widgetService;

    public function __construct(WidgetService $widgetService)
    {
        $this->widgetService = $widgetService;
    }

    /**
     * Экспортировать дашборд в PDF
     * 
     * @param int $dashboardId ID дашборда
     * @param array $options Опции экспорта
     * @return string Путь к файлу
     */
    public function exportDashboardToPDF(int $dashboardId, array $options = []): string
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        // Собираем данные для всех виджетов дашборда
        $data = $this->collectDashboardData($dashboard, $options);
        
        // Генерируем HTML
        $html = $this->generateDashboardHTML($dashboard, $data, $options);
        
        // Конвертируем в PDF
        $pdfPath = $this->convertHTMLtoPDF($html, $dashboard->name);
        
        return $pdfPath;
    }

    /**
     * Экспортировать дашборд в Excel
     * 
     * @param int $dashboardId ID дашборда
     * @param array $options Опции экспорта
     * @return string Путь к файлу
     */
    public function exportDashboardToExcel(int $dashboardId, array $options = []): string
    {
        $dashboard = Dashboard::findOrFail($dashboardId);
        
        // Собираем данные для всех виджетов дашборда
        $data = $this->collectDashboardData($dashboard, $options);
        
        // Генерируем Excel файл
        $excelPath = $this->generateExcelFile($dashboard, $data, $options);
        
        return $excelPath;
    }

    /**
     * Сгенерировать scheduled report
     * 
     * @param int $reportId ID scheduled report
     * @return array Пути к сгенерированным файлам
     */
    public function generateScheduledReport(int $reportId): array
    {
        $report = ScheduledReport::findOrFail($reportId);
        
        $report->markAsStarted();
        
        try {
            $dashboard = Dashboard::findOrFail($report->dashboard_id);
            
            $files = [];
            $exportFormats = $report->export_formats ?? ['pdf'];
            
            $options = [
                'filters' => $report->filters,
                'widgets' => $report->widgets,
                'include_raw_data' => $report->include_raw_data ?? false,
            ];
            
            // Генерируем файлы в требуемых форматах
            if (in_array('pdf', $exportFormats)) {
                $files['pdf'] = $this->exportDashboardToPDF($dashboard->id, $options);
            }
            
            if (in_array('excel', $exportFormats)) {
                $files['excel'] = $this->exportDashboardToExcel($dashboard->id, $options);
            }
            
            $report->markAsSuccess();
            
            return $files;
            
        } catch (\Exception $e) {
            $report->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Отправить отчет по email
     * 
     * @param int $reportId ID scheduled report
     * @param array $files Пути к файлам для отправки
     * @return bool
     */
    public function sendReportByEmail(int $reportId, array $files): bool
    {
        $report = ScheduledReport::findOrFail($reportId);
        
        // TODO: Реализовать после создания системы email уведомлений
        // Пока логируем
        
        LogService::info('Report email queued', [
            'report_id' => $reportId,
            'recipients' => $report->recipients,
            'files' => array_keys($files),
        ]);
        
        return true;
    }

    /**
     * Получить доступные форматы экспорта
     * 
     * @return array
     */
    public function getAvailableFormats(): array
    {
        return [
            'pdf' => [
                'name' => 'PDF',
                'description' => 'Portable Document Format',
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
            ],
            'excel' => [
                'name' => 'Excel',
                'description' => 'Microsoft Excel Spreadsheet',
                'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => 'xlsx',
            ],
        ];
    }

    // ==================== PROTECTED HELPER METHODS ====================

    /**
     * Собрать данные дашборда для экспорта
     */
    protected function collectDashboardData(Dashboard $dashboard, array $options): array
    {
        $widgets = $options['widgets'] ?? $dashboard->widgets;
        $filters = $options['filters'] ?? $dashboard->filters;
        
        // Период для анализа
        $from = isset($filters['from']) ? Carbon::parse($filters['from']) : Carbon::now()->subMonth();
        $to = isset($filters['to']) ? Carbon::parse($filters['to']) : Carbon::now();
        
        $data = [
            'dashboard' => $dashboard,
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'widgets_data' => [],
        ];
        
        // Собираем данные для каждого виджета
        foreach ($widgets as $widget) {
            $widgetType = $widget['type'] ?? 'unknown';
            
            try {
                $widgetData = $this->getWidgetData($widgetType, $dashboard->organization_id, $from, $to, $filters);
                $data['widgets_data'][$widget['id']] = [
                    'type' => $widgetType,
                    'data' => $widgetData,
                ];
            } catch (\Exception $e) {
                $data['widgets_data'][$widget['id']] = [
                    'type' => $widgetType,
                    'error' => $e->getMessage(),
                ];
            }
        }
        
        return $data;
    }

    /**
     * Получить данные виджета
     */
    protected function getWidgetData(string $widgetType, int $organizationId, Carbon $from, Carbon $to, array $filters): array
    {
        try {
            $widgetTypeEnum = WidgetType::from($widgetType);
            
            $request = new WidgetDataRequest(
                widgetType: $widgetTypeEnum,
                organizationId: $organizationId,
                from: $from,
                to: $to,
                projectId: $filters['project_id'] ?? null,
                contractId: $filters['contract_id'] ?? null,
                employeeId: $filters['employee_id'] ?? null,
                filters: $filters,
                options: $filters['options'] ?? []
            );
            
            $response = $this->widgetService->getWidgetData($widgetTypeEnum, $request);
            
            return $response->data;
            
        } catch (\ValueError $e) {
            return ['error' => 'Unsupported widget type: ' . $widgetType];
        } catch (\Exception $e) {
            return ['error' => 'Failed to get widget data: ' . $e->getMessage()];
        }
    }

    /**
     * Сгенерировать HTML для дашборда
     */
    protected function generateDashboardHTML(Dashboard $dashboard, array $data, array $options): string
    {
        // Простой HTML шаблон для PDF
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . htmlspecialchars($dashboard->name) . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
        h2 { color: #555; margin-top: 30px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 10px; text-align: left; border: 1px solid #ddd; }
        th { background-color: #f8f9fa; font-weight: bold; }
        .widget { margin: 30px 0; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px; }
        .meta { color: #666; font-size: 0.9em; }
        .footer { margin-top: 50px; text-align: center; color: #999; font-size: 0.8em; }
    </style>
</head>
<body>';
        
        // Заголовок
        $html .= '<h1>' . htmlspecialchars($dashboard->name) . '</h1>';
        $html .= '<p class="meta">Сгенерировано: ' . Carbon::now()->format('d.m.Y H:i') . '</p>';
        
        if (isset($data['period'])) {
            $html .= '<p class="meta">Период: ' . Carbon::parse($data['period']['from'])->format('d.m.Y') . ' - ' . Carbon::parse($data['period']['to'])->format('d.m.Y') . '</p>';
        }
        
        // Виджеты
        foreach ($data['widgets_data'] as $widgetId => $widgetInfo) {
            $html .= '<div class="widget">';
            $html .= '<h2>' . $this->getWidgetTitle($widgetInfo['type']) . '</h2>';
            
            if (isset($widgetInfo['error'])) {
                $html .= '<p style="color: red;">Ошибка: ' . htmlspecialchars($widgetInfo['error']) . '</p>';
            } else {
                $html .= $this->formatWidgetDataAsHTML($widgetInfo['type'], $widgetInfo['data']);
            }
            
            $html .= '</div>';
        }
        
        // Футер
        $html .= '<div class="footer">';
        $html .= '<p>ProHelper Advanced Dashboard</p>';
        $html .= '</div>';
        
        $html .= '</body></html>';
        
        return $html;
    }

    /**
     * Форматировать данные виджета в HTML
     */
    protected function formatWidgetDataAsHTML(string $widgetType, array $data): string
    {
        // Простое форматирование в таблицу
        $html = '<table>';
        
        // Рекурсивно выводим данные
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $html .= '<tr><td colspan="2"><strong>' . htmlspecialchars($key) . '</strong></td></tr>';
                $html .= '<tr><td colspan="2">' . $this->formatArrayAsHTML($value) . '</td></tr>';
            } else {
                $html .= '<tr>';
                $html .= '<td><strong>' . htmlspecialchars($key) . '</strong></td>';
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
                $html .= '</tr>';
            }
        }
        
        $html .= '</table>';
        
        return $html;
    }

    /**
     * Форматировать массив в HTML
     */
    protected function formatArrayAsHTML(array $data, int $depth = 0): string
    {
        if ($depth > 2) {
            return '<pre>' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
        }
        
        $html = '<table style="margin-left: ' . ($depth * 20) . 'px;">';
        
        foreach ($data as $key => $value) {
            $html .= '<tr>';
            
            if (is_numeric($key)) {
                if (is_array($value)) {
                    $html .= '<td colspan="2">' . $this->formatArrayAsHTML($value, $depth + 1) . '</td>';
                } else {
                    $html .= '<td colspan="2">' . htmlspecialchars($value) . '</td>';
                }
            } else {
                $html .= '<td><strong>' . htmlspecialchars($key) . '</strong></td>';
                
                if (is_array($value)) {
                    $html .= '<td>' . $this->formatArrayAsHTML($value, $depth + 1) . '</td>';
                } else {
                    $html .= '<td>' . htmlspecialchars($value) . '</td>';
                }
            }
            
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        
        return $html;
    }

    /**
     * Конвертировать HTML в PDF
     */
    protected function convertHTMLtoPDF(string $html, string $filename): string
    {
        try {
            $filename = Str::slug($filename) . '_' . time() . '.pdf';
            $path = 'exports/dashboards/' . $filename;
            
            $pdf = Pdf::loadHTML($html)
                ->setPaper('a4', 'portrait')
                ->setOption('margin-top', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10)
                ->setOption('margin-right', 10)
                ->setOption('enable-local-file-access', true);
            
            $pdfContent = $pdf->output();
            
            Storage::disk('local')->put($path, $pdfContent);
            
            LogService::info('Dashboard exported to PDF', [
                'path' => $path,
                'size' => strlen($pdfContent),
            ]);
            
            return $path;
            
        } catch (\Exception $e) {
            LogService::error('PDF export failed, falling back to HTML', [
                'error' => $e->getMessage(),
            ]);
            
            $filename = Str::slug($filename) . '_' . time() . '.html';
            $path = 'exports/dashboards/' . $filename;
            
            Storage::disk('local')->put($path, $html);
            
            return $path;
        }
    }

    /**
     * Сгенерировать Excel файл
     */
    protected function generateExcelFile(Dashboard $dashboard, array $data, array $options): string
    {
        try {
            $filename = Str::slug($dashboard->name) . '_' . time() . '.xlsx';
            $path = 'exports/dashboards/' . $filename;
            $fullPath = Storage::disk('local')->path($path);
            
            $directory = dirname($fullPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }
            
            $exporter = new \App\BusinessModules\Features\AdvancedDashboard\Exports\DashboardExport($data);
            $exporter->generate();
            $exporter->save($fullPath);
            
            LogService::info('Dashboard exported to Excel', [
                'path' => $path,
                'size' => filesize($fullPath),
            ]);
            
            return $path;
            
        } catch (\Exception $e) {
            LogService::error('Excel export failed, falling back to CSV', [
                'error' => $e->getMessage(),
            ]);
            
            $filename = Str::slug($dashboard->name) . '_' . time() . '.csv';
            $path = 'exports/dashboards/' . $filename;
            
            $csv = $this->convertDataToCSV($data);
            
            Storage::disk('local')->put($path, $csv);
            
            return $path;
        }
    }

    /**
     * Конвертировать данные в CSV
     */
    protected function convertDataToCSV(array $data): string
    {
        $csv = '';
        
        // Заголовок
        $dashboard = $data['dashboard'];
        $csv .= '"' . $dashboard->name . '"' . "\n";
        $csv .= '"Период: ' . Carbon::parse($data['period']['from'])->format('d.m.Y') . ' - ' . Carbon::parse($data['period']['to'])->format('d.m.Y') . '"' . "\n\n";
        
        // Данные виджетов
        foreach ($data['widgets_data'] as $widgetId => $widgetInfo) {
            $csv .= '"' . $this->getWidgetTitle($widgetInfo['type']) . '"' . "\n";
            
            if (isset($widgetInfo['error'])) {
                $csv .= '"Ошибка: ' . $widgetInfo['error'] . '"' . "\n\n";
            } else {
                $csv .= $this->flattenArrayToCSV($widgetInfo['data']);
                $csv .= "\n";
            }
        }
        
        return $csv;
    }

    /**
     * Сплющить массив в CSV строку
     */
    protected function flattenArrayToCSV(array $data, string $prefix = ''): string
    {
        $csv = '';
        
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? $prefix . '.' . $key : $key;
            
            if (is_array($value)) {
                $csv .= $this->flattenArrayToCSV($value, $fullKey);
            } else {
                $csv .= '"' . $fullKey . '","' . $value . '"' . "\n";
            }
        }
        
        return $csv;
    }

    /**
     * Получить название виджета
     */
    protected function getWidgetTitle(string $widgetType): string
    {
        $titles = [
            'cash_flow' => 'Движение денежных средств',
            'profit_loss' => 'Прибыли и убытки',
            'roi' => 'Рентабельность инвестиций',
            'revenue_forecast' => 'Прогноз доходов',
            'receivables_payables' => 'Дебиторская и кредиторская задолженность',
            'budget_risk' => 'Риски превышения бюджета',
            'kpi' => 'KPI сотрудников',
            'top_performers' => 'Топ исполнители',
            'resource_utilization' => 'Загрузка ресурсов',
        ];
        
        return $titles[$widgetType] ?? ucfirst(str_replace('_', ' ', $widgetType));
    }
}

