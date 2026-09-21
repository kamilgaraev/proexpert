<?php

declare(strict_types=1);

namespace App\Services\MaterialConsumption;

use App\BusinessModules\Features\BudgetEstimates\Services\MaterialConsumptionStatementService;
use App\Models\MaterialConsumptionStatement;
use App\Services\Storage\FileService;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class MaterialConsumptionStatementExportService
{
    public function __construct(
        private readonly MaterialConsumptionStatementService $statementService,
        private readonly FileService $fileService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dataset(MaterialConsumptionStatement $statement): array
    {
        return $this->statementService->exportDataset($statement);
    }

    /**
     * @param  array<string, mixed>  $dataset
     */
    public function fillSpreadsheet(Spreadsheet $spreadsheet, array $dataset): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('М-29');
        $this->writeTitle($sheet, $dataset);
        $row = $this->writeSectionI($sheet, $dataset, 12);
        $this->writeSectionII($sheet, $dataset, $row + 2);
        if (! empty($dataset['is_draft'])) {
            $sheet->setCellValue('A2', 'ЧЕРНОВИК');
        }
        foreach (range('A', 'G') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    /**
     * @return array{url: string, checksum: array<string, mixed>}
     */
    public function exportXlsx(MaterialConsumptionStatement $statement): array
    {
        $dataset = $this->dataset($statement);
        $spreadsheet = new Spreadsheet;
        $this->fillSpreadsheet($spreadsheet, $dataset);
        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = (string) ob_get_clean();
        $path = 'org-'.$statement->organization_id.'/exports/materials/m29/'.$statement->number.'.xlsx';
        $this->fileService->disk($statement->organization)->put($path, $content);

        return [
            'url' => $this->fileService->temporaryUrl($path, 15),
            'checksum' => $this->statementService->checksum($dataset),
        ];
    }

    /**
     * @return array{url: string, checksum: array<string, mixed>}
     */
    public function exportPdf(MaterialConsumptionStatement $statement): array
    {
        $dataset = $this->dataset($statement);
        $pdf = Pdf::loadView('materials.exports.m29', $dataset)->setPaper('a4', 'landscape');
        $content = $pdf->output();
        $path = 'org-'.$statement->organization_id.'/exports/materials/m29/'.$statement->number.'.pdf';
        $this->fileService->disk($statement->organization)->put($path, $content);

        return [
            'url' => $this->fileService->temporaryUrl($path, 15),
            'checksum' => $this->statementService->checksum($dataset),
        ];
    }

    /**
     * @param  array<string, mixed>  $dataset
     */
    private function writeTitle(Worksheet $sheet, array $dataset): void
    {
        $sheet->setCellValue('A1', 'Форма № М-29');
        $sheet->setCellValue('E1', 'Утверждена ЦСУ СССР 24.11.1982 № 613');
        $sheet->setCellValue('A3', 'Отчёт о расходе основных материалов в строительстве в сопоставлении с производственными нормами');
        $sheet->mergeCells('A3:G3');
        $organization = $dataset['organization']['name'] ?? '';
        $project = $dataset['project']['name'] ?? '';
        $sheet->setCellValue('A5', 'Организация');
        $sheet->setCellValue('B5', $organization);
        $sheet->setCellValue('A6', 'Объект');
        $sheet->setCellValue('B6', $project);
        $sheet->setCellValue('A7', 'Прораб');
        $sheet->setCellValue('B7', $dataset['foreman_name'] ?? '');
        $sheet->setCellValue('A8', 'Период');
        $sheet->setCellValue('B8', $this->periodLabel($dataset));
        $sheet->setCellValue('A9', 'Номер');
        $sheet->setCellValue('B9', $dataset['number'] ?? '');
        $sheet->getStyle('A3')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    /**
     * @param  array<string, mixed>  $dataset
     */
    private function writeSectionI(Worksheet $sheet, array $dataset, int $startRow): int
    {
        $sheet->setCellValue("A{$startRow}", 'Раздел I. Нормативная потребность');
        $sheet->mergeCells("A{$startRow}:G{$startRow}");
        $header = $startRow + 1;
        $sheet->fromArray([
            'Наименование работ',
            'Единица',
            'Объём',
            'Наименование материалов',
            'Норма на единицу',
            'Нормативная потребность',
            'Основание нормы',
        ], null, "A{$header}");
        $this->headerStyle($sheet, "A{$header}:G{$header}");
        $row = $header + 1;
        foreach ($dataset['section_i'] ?? [] as $line) {
            $sheet->fromArray([
                $line['work_name'] ?? '',
                $line['work_unit'] ?? '',
                $line['accepted_volume'] ?? '',
                $line['material_name'] ?? '',
                $line['rate_per_unit'] ?? '',
                $line['normative_need'] ?? '',
                $line['rate_basis_text'] ?? '',
            ], null, "A{$row}");
            $row++;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $dataset
     */
    private function writeSectionII(Worksheet $sheet, array $dataset, int $startRow): void
    {
        $sheet->setCellValue("A{$startRow}", 'Раздел II. Сопоставление фактического расхода с производственными нормами');
        $sheet->mergeCells("A{$startRow}:G{$startRow}");
        $header = $startRow + 1;
        $sheet->fromArray([
            'Наименование материалов',
            'Единица',
            'Расход по норме',
            'Фактический расход',
            'Экономия',
            'Перерасход',
            'Причины',
        ], null, "A{$header}");
        $this->headerStyle($sheet, "A{$header}:G{$header}");
        $row = $header + 1;
        foreach ($dataset['section_ii'] ?? [] as $line) {
            $sheet->fromArray([
                $line['material_name'] ?? '',
                $line['material_unit'] ?? '',
                $line['normative_consumption'] ?? '',
                $line['actual_consumption'] ?? '',
                $line['economy'] ?? '0',
                $line['overconsumption'] ?? '0',
                $line['deviation_reason'] ?? '',
            ], null, "A{$row}");
            $row++;
        }
        $totals = $dataset['totals'] ?? [];
        $sheet->fromArray([
            'Итого',
            '',
            $totals['normative_consumption'] ?? '0',
            $totals['actual_consumption'] ?? '0',
            $totals['economy'] ?? '0',
            $totals['overconsumption'] ?? '0',
            '',
        ], null, "A{$row}");
        $sheet->getStyle("A{$row}:G{$row}")->getFont()->setBold(true);
    }

    private function headerStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->getFont()->setBold(true);
        $sheet->getStyle($range)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    /**
     * @param  array<string, mixed>  $dataset
     */
    private function periodLabel(array $dataset): string
    {
        $start = $dataset['period_start'] ?? '';
        $end = $dataset['period_end'] ?? '';

        return trim($start.' — '.$end);
    }
}
