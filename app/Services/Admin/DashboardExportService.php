<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Material;
use App\Models\CompletedWork;
use App\Services\Storage\FileService;
use Carbon\Carbon;
use Illuminate\Support\Str;

class DashboardExportService
{
    public function __construct(private readonly FileService $fileService)
    {
    }

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

    public function exportProjectsForMap(int $organizationId, array $params): array
    {
        $organization = Organization::query()->findOrFail($organizationId);
        $format = (string) ($params['format'] ?? 'excel');
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $bounds = is_array($params['bounds'] ?? null) ? $params['bounds'] : [];
        $projects = $this->buildMapProjectExportQuery($organizationId, $filters, $bounds)->get();
        $extension = $format === 'csv' ? 'csv' : 'xlsx';
        $filename = 'dashboard_projects_' . now()->format('Y-m-d_His') . '.' . $extension;
        $contentType = $format === 'csv'
            ? 'text/csv; charset=UTF-8'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $content = $format === 'csv'
            ? $this->buildProjectsCsv($projects)
            : $this->buildProjectsXlsx($projects);

        $path = $this->fileService->putContent(
            $content,
            'dashboard/exports',
            Str::uuid()->toString() . '.' . $extension,
            'private',
            $organization,
        );

        if ($path === false) {
            throw new \RuntimeException('dashboard_export_store_failed');
        }

        $url = $this->fileService->temporaryUrl(
            $path,
            15,
            $organization,
            [
                'ResponseContentType' => $contentType,
                'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"',
            ],
        );

        if ($url === null) {
            throw new \RuntimeException('dashboard_export_url_failed');
        }

        return [
            'file_url' => $url,
            'filename' => $filename,
            'count' => $projects->count(),
        ];
    }

    private function buildMapProjectExportQuery(int $organizationId, array $filters, array $bounds)
    {
        $query = Project::query()
            ->where('organization_id', $organizationId)
            ->select(['id', 'name', 'address', 'budget_amount', 'status', 'start_date', 'end_date', 'latitude', 'longitude']);

        if (isset($filters['project_id'])) {
            $query->where('id', (int) $filters['project_id']);
        }

        if (!empty($filters['status']) && is_array($filters['status'])) {
            $query->whereIn('status', array_values($filters['status']));
        }

        if (isset($filters['budget_min'])) {
            $query->where('budget_amount', '>=', (float) $filters['budget_min']);
        }

        if (isset($filters['budget_max'])) {
            $query->where('budget_amount', '<=', (float) $filters['budget_max']);
        }

        if (!empty($filters['start_date'])) {
            $query->whereDate('start_date', '>=', $filters['start_date']);
        }

        if (!empty($filters['end_date'])) {
            $query->whereDate('end_date', '<=', $filters['end_date']);
        }

        if (
            isset($bounds['north'], $bounds['south'], $bounds['east'], $bounds['west'])
            && is_numeric($bounds['north'])
            && is_numeric($bounds['south'])
            && is_numeric($bounds['east'])
            && is_numeric($bounds['west'])
        ) {
            $query
                ->whereBetween('latitude', [(float) $bounds['south'], (float) $bounds['north']])
                ->whereBetween('longitude', [(float) $bounds['west'], (float) $bounds['east']]);
        }

        return $query->orderBy('name');
    }

    private function buildProjectsCsv($projects): string
    {
        $handle = fopen('php://temp', 'r+');
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($handle, ['ID', 'Name', 'Address', 'Budget', 'Status', 'Start date', 'End date', 'Latitude', 'Longitude'], ';');

        foreach ($projects as $project) {
            fputcsv($handle, $this->mapProjectExportRow($project), ';');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return is_string($content) ? $content : '';
    }

    private function buildProjectsXlsx($projects): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Projects');
        $sheet->fromArray([['ID', 'Name', 'Address', 'Budget', 'Status', 'Start date', 'End date', 'Latitude', 'Longitude']], null, 'A1');
        $this->styleHeaderRow($sheet, 1);

        $row = 2;
        foreach ($projects as $project) {
            $sheet->fromArray([$this->mapProjectExportRow($project)], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        return is_string($content) ? $content : '';
    }

    private function mapProjectExportRow(Project $project): array
    {
        return [
            $project->id,
            $project->name,
            $project->address ?? '',
            (float) ($project->budget_amount ?? 0),
            $project->status ?? '',
            $project->start_date?->format('Y-m-d') ?? '',
            $project->end_date?->format('Y-m-d') ?? '',
            $project->latitude ?? '',
            $project->longitude ?? '',
        ];
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































