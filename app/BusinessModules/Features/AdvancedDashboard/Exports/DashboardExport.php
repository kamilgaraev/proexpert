<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Carbon\Carbon;

class DashboardExport
{
    protected array $data;
    protected Spreadsheet $spreadsheet;
    protected int $currentRow = 1;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->spreadsheet = new Spreadsheet();
    }

    public function generate(): Spreadsheet
    {
        $sheet = $this->spreadsheet->getActiveSheet();
        $dashboard = $this->data['dashboard'];
        
        $sheet->setTitle(mb_substr($dashboard->name, 0, 31));
        
        $this->addHeader($sheet);
        $this->addMetadata($sheet);
        $this->addWidgetsData($sheet);
        
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        
        return $this->spreadsheet;
    }

    protected function addHeader($sheet): void
    {
        $dashboard = $this->data['dashboard'];
        
        $sheet->setCellValue('A' . $this->currentRow, $dashboard->name);
        $sheet->getStyle('A' . $this->currentRow)->getFont()->setSize(16)->setBold(true);
        $this->currentRow++;
        
        $sheet->setCellValue('A' . $this->currentRow, 'Сгенерировано: ' . Carbon::now()->format('d.m.Y H:i'));
        $sheet->getStyle('A' . $this->currentRow)->getFont()->setSize(10)->setItalic(true);
        $this->currentRow++;
        
        if (isset($this->data['period'])) {
            $from = Carbon::parse($this->data['period']['from'])->format('d.m.Y');
            $to = Carbon::parse($this->data['period']['to'])->format('d.m.Y');
            $sheet->setCellValue('A' . $this->currentRow, "Период: {$from} - {$to}");
            $sheet->getStyle('A' . $this->currentRow)->getFont()->setSize(10)->setItalic(true);
            $this->currentRow++;
        }
        
        $this->currentRow++;
    }

    protected function addMetadata($sheet): void
    {
        $dashboard = $this->data['dashboard'];
        
        $metadata = [
            ['Параметр', 'Значение'],
            ['ID дашборда', $dashboard->id],
            ['Шаблон', $dashboard->template ?? 'Пользовательский'],
            ['Организация', $dashboard->organization_id],
            ['Создан', $dashboard->created_at->format('d.m.Y H:i')],
        ];
        
        foreach ($metadata as $row) {
            $sheet->setCellValue('A' . $this->currentRow, $row[0]);
            $sheet->setCellValue('B' . $this->currentRow, $row[1]);
            
            if ($this->currentRow == 5) {
                $sheet->getStyle('A' . $this->currentRow . ':B' . $this->currentRow)
                    ->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFE9ECEF');
                $sheet->getStyle('A' . $this->currentRow . ':B' . $this->currentRow)
                    ->getFont()->setBold(true);
            }
            
            $this->currentRow++;
        }
        
        $this->currentRow++;
    }

    protected function addWidgetsData($sheet): void
    {
        foreach ($this->data['widgets_data'] as $widgetId => $widgetInfo) {
            $sheet->setCellValue('A' . $this->currentRow, $this->getWidgetTitle($widgetInfo['type']));
            $sheet->getStyle('A' . $this->currentRow)
                ->getFont()->setSize(14)->setBold(true);
            $sheet->getStyle('A' . $this->currentRow)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF007BFF');
            $sheet->getStyle('A' . $this->currentRow)
                ->getFont()->getColor()->setARGB('FFFFFFFF');
            
            $sheet->mergeCells('A' . $this->currentRow . ':D' . $this->currentRow);
            $this->currentRow++;
            
            if (isset($widgetInfo['error'])) {
                $sheet->setCellValue('A' . $this->currentRow, 'Ошибка: ' . $widgetInfo['error']);
                $sheet->getStyle('A' . $this->currentRow)->getFont()->getColor()->setARGB('FFDC3545');
                $this->currentRow++;
            } else {
                $this->addWidgetDataRows($sheet, $widgetInfo['data']);
            }
            
            $this->currentRow++;
        }
    }

    protected function addWidgetDataRows($sheet, array $data, string $prefix = '', int $level = 0): void
    {
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? $prefix . ' > ' . $key : $key;
            
            if (is_array($value)) {
                if ($this->isAssociativeArray($value)) {
                    $sheet->setCellValue('A' . $this->currentRow, $fullKey);
                    $sheet->getStyle('A' . $this->currentRow)->getFont()->setBold(true);
                    $this->currentRow++;
                    
                    $this->addWidgetDataRows($sheet, $value, $fullKey, $level + 1);
                } else {
                    $sheet->setCellValue('A' . $this->currentRow, $fullKey);
                    $this->currentRow++;
                    
                    $headers = array_keys($value[0] ?? []);
                    if (!empty($headers)) {
                        $col = 'A';
                        foreach ($headers as $header) {
                            $sheet->setCellValue($col . $this->currentRow, $header);
                            $sheet->getStyle($col . $this->currentRow)
                                ->getFill()
                                ->setFillType(Fill::FILL_SOLID)
                                ->getStartColor()->setARGB('FFF8F9FA');
                            $sheet->getStyle($col . $this->currentRow)->getFont()->setBold(true);
                            $col++;
                        }
                        $this->currentRow++;
                        
                        foreach ($value as $item) {
                            $col = 'A';
                            foreach ($headers as $header) {
                                $cellValue = $item[$header] ?? '';
                                if (is_array($cellValue)) {
                                    $cellValue = json_encode($cellValue, JSON_UNESCAPED_UNICODE);
                                }
                                $sheet->setCellValue($col . $this->currentRow, $cellValue);
                                $col++;
                            }
                            $this->currentRow++;
                        }
                    }
                }
            } else {
                $sheet->setCellValue('A' . $this->currentRow, $fullKey);
                $sheet->setCellValue('B' . $this->currentRow, $value);
                
                if ($level == 0) {
                    $sheet->getStyle('A' . $this->currentRow . ':B' . $this->currentRow)
                        ->getBorders()
                        ->getBottom()
                        ->setBorderStyle(Border::BORDER_THIN);
                }
                
                $this->currentRow++;
            }
        }
    }

    protected function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }
        
        return array_keys($array) !== range(0, count($array) - 1);
    }

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

    public function save(string $path): void
    {
        $writer = new Xlsx($this->spreadsheet);
        $writer->save($path);
    }
}

