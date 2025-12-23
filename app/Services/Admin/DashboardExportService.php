<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use App\Models\Contract;
use App\Models\Project;
use App\Models\Material;
use App\Models\CompletedWork;
use Carbon\Carbon;

class DashboardExportService
{
    /**
     * Экспорт сводки дашборда в Excel
     */
    public function exportSummary(int $organizationId, ?int $projectId = null): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Сводка дашборда');

        // Заголовки
        $headers = ['Показатель', 'Значение'];
        $sheet->fromArray([$headers], null, 'A1');
        $this->styleHeaderRow($sheet, 1);

        $row = 2;

        // Контракты
        $contractsQuery = Contract::where('organization_id', $organizationId);
        if ($projectId) {
            $contractsQuery->where('project_id', $projectId);
        }

        $contractsTotal = $contractsQuery->count();
        $contractsAmount = $contractsQuery->sum('total_amount');

        $sheet->setCellValue("A{$row}", 'Всего контрактов');
        $sheet->setCellValue("B{$row}", $contractsTotal);
        $row++;

        $sheet->setCellValue("A{$row}", 'Сумма контрактов');
        $sheet->setCellValue("B{$row}", number_format($contractsAmount, 2, '.', ' ') . ' ₽');
        $row++;

        // Выполненные работы
        $worksQuery = CompletedWork::where('organization_id', $organizationId);
        if ($projectId) {
            $worksQuery->where('project_id', $projectId);
        }

        $worksTotal = $worksQuery->count();
        $worksAmount = $worksQuery->where('status', 'confirmed')->sum('total_amount');

        $sheet->setCellValue("A{$row}", 'Всего выполненных работ');
        $sheet->setCellValue("B{$row}", $worksTotal);
        $row++;

        $sheet->setCellValue("A{$row}", 'Сумма подтвержденных работ');
        $sheet->setCellValue("B{$row}", number_format($worksAmount, 2, '.', ' ') . ' ₽');
        $row++;

        // Проекты
        if (!$projectId) {
            $projectsTotal = Project::where('organization_id', $organizationId)->count();
            $sheet->setCellValue("A{$row}", 'Всего проектов');
            $sheet->setCellValue("B{$row}", $projectsTotal);
            $row++;
        }

        // Материалы
        $materialsTotal = Material::where('organization_id', $organizationId)->count();
        $sheet->setCellValue("A{$row}", 'Всего материалов');
        $sheet->setCellValue("B{$row}", $materialsTotal);
        $row++;

        // Автоподбор ширины колонок
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);

        // Сохранение во временный файл
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'dashboard_export_');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Экспорт контрактов в Excel
     */
    public function exportContracts(int $organizationId, ?int $projectId = null, array $filters = []): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Контракты');

        // Заголовки
        $headers = ['Номер', 'Проект', 'Подрядчик', 'Сумма', 'Статус', 'Дата начала', 'Дата окончания'];
        $sheet->fromArray([$headers], null, 'A1');
        $this->styleHeaderRow($sheet, 1);

        // Данные
        $query = Contract::where('organization_id', $organizationId)
            ->with(['project:id,name', 'contractor:id,name']);

        if ($projectId) {
            $query->where('project_id', $projectId);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $contracts = $query->get(['id', 'number', 'total_amount', 'status', 'start_date', 'end_date', 'project_id', 'contractor_id']);

        $row = 2;
        foreach ($contracts as $contract) {
            $sheet->setCellValue("A{$row}", $contract->number);
            $sheet->setCellValue("B{$row}", $contract->project?->name ?? '');
            $sheet->setCellValue("C{$row}", $contract->contractor?->name ?? '');
            $sheet->setCellValue("D{$row}", number_format($contract->total_amount, 2, '.', ' ') . ' ₽');
            $sheet->setCellValue("E{$row}", $contract->status->value);
            $sheet->setCellValue("F{$row}", $contract->start_date?->format('Y-m-d') ?? '');
            $sheet->setCellValue("G{$row}", $contract->end_date?->format('Y-m-d') ?? '');
            $row++;
        }

        // Автоподбор ширины колонок
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Сохранение во временный файл
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'contracts_export_');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Экспорт проектов в Excel
     */
    public function exportProjects(int $organizationId, array $filters = []): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Проекты');

        // Заголовки
        $headers = ['Название', 'Адрес', 'Бюджет', 'Статус', 'Дата начала', 'Дата окончания'];
        $sheet->fromArray([$headers], null, 'A1');
        $this->styleHeaderRow($sheet, 1);

        // Данные
        $query = Project::where('organization_id', $organizationId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $projects = $query->get(['id', 'name', 'address', 'budget_amount', 'status', 'start_date', 'end_date']);

        $row = 2;
        foreach ($projects as $project) {
            $sheet->setCellValue("A{$row}", $project->name);
            $sheet->setCellValue("B{$row}", $project->address ?? '');
            $sheet->setCellValue("C{$row}", number_format($project->budget_amount ?? 0, 2, '.', ' ') . ' ₽');
            $sheet->setCellValue("D{$row}", $project->status ?? '');
            $sheet->setCellValue("E{$row}", $project->start_date?->format('Y-m-d') ?? '');
            $sheet->setCellValue("F{$row}", $project->end_date?->format('Y-m-d') ?? '');
            $row++;
        }

        // Автоподбор ширины колонок
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Сохранение во временный файл
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'projects_export_');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Экспорт материалов в Excel
     */
    public function exportMaterials(int $organizationId, array $filters = []): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Материалы');

        // Заголовки
        $headers = ['Название', 'Код', 'Единица измерения', 'Цена по умолчанию', 'Категория'];
        $sheet->fromArray([$headers], null, 'A1');
        $this->styleHeaderRow($sheet, 1);

        // Данные
        $query = Material::where('organization_id', $organizationId)
            ->with('measurementUnit:id,name');

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        $materials = $query->get(['id', 'name', 'code', 'default_price', 'category', 'measurement_unit_id']);

        $row = 2;
        foreach ($materials as $material) {
            $sheet->setCellValue("A{$row}", $material->name);
            $sheet->setCellValue("B{$row}", $material->code ?? '');
            $sheet->setCellValue("C{$row}", $material->measurementUnit?->name ?? '');
            $sheet->setCellValue("D{$row}", number_format($material->default_price ?? 0, 2, '.', ' ') . ' ₽');
            $sheet->setCellValue("E{$row}", $material->category ?? '');
            $row++;
        }

        // Автоподбор ширины колонок
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Сохранение во временный файл
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'materials_export_');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Экспорт в CSV
     */
    public function exportToCsv(array $data, array $headers): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csv_export_');
        $handle = fopen($tempFile, 'w');

        // BOM для корректного отображения кириллицы в Excel
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Заголовки
        fputcsv($handle, $headers, ';');

        // Данные
        foreach ($data as $row) {
            fputcsv($handle, $row, ';');
        }

        fclose($handle);
        return $tempFile;
    }

    /**
     * Стилизация заголовков
     */
    private function styleHeaderRow($sheet, int $row): void
    {
        $sheet->getStyle("A{$row}:Z{$row}")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);
    }
}





























