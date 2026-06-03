<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

use function trans_message;

final class WarehouseCustodyExportService
{
    private const MODE_DETAIL = 'detail';

    private const MODE_SUMMARY = 'summary';

    public function __construct(
        private readonly WarehouseCustodyService $custodyService,
        private readonly FileService $fileService
    ) {}

    public function export(int $organizationId, array $filters, string $mode): string
    {
        $mode = $this->normalizeMode($mode);
        $spreadsheet = new Spreadsheet;

        if ($mode === self::MODE_DETAIL) {
            $this->fillDetailSheet($spreadsheet, $organizationId, $filters);
        } else {
            $this->fillSummarySheet($spreadsheet, $organizationId, $filters);
        }

        return $this->saveSpreadsheet($spreadsheet, $organizationId, $mode);
    }

    public function temporaryUrl(string $path, int $minutes = 15): ?string
    {
        return $this->fileService->temporaryUrl($path, $minutes);
    }

    private function normalizeMode(string $mode): string
    {
        $mode = trim($mode);

        if ($mode === '') {
            return self::MODE_SUMMARY;
        }

        if (! in_array($mode, [self::MODE_DETAIL, self::MODE_SUMMARY], true)) {
            throw new InvalidArgumentException(trans_message('basic_warehouse.custody.errors.invalid_export_mode'));
        }

        return $mode;
    }

    private function fillDetailSheet(Spreadsheet $spreadsheet, int $organizationId, array $filters): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle(trans_message('basic_warehouse.custody.export.detail_title')));
        $headers = [
            trans_message('basic_warehouse.custody.export.headers.project'),
            trans_message('basic_warehouse.custody.export.headers.responsible'),
            trans_message('basic_warehouse.custody.export.headers.material'),
            trans_message('basic_warehouse.custody.export.headers.unit'),
            trans_message('basic_warehouse.custody.export.headers.available_quantity'),
            trans_message('basic_warehouse.custody.export.headers.reserved_quantity'),
            trans_message('basic_warehouse.custody.export.headers.last_movement_at'),
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $balances = $this->custodyService->getBalances(
            $organizationId,
            $filters['project_id'] ?? null,
            $filters['responsible_user_id'] ?? null,
            $filters['material_id'] ?? null,
            $filters['search'] ?? null
        );

        $row = 2;
        foreach ($balances as $balance) {
            /** @var WarehouseBalance $balance */
            $material = $balance->material;
            $sheet->fromArray([[
                $balance->warehouse?->project?->name,
                $balance->warehouse?->responsibleUser?->name,
                $material?->name,
                $material?->measurementUnit?->short_name ?? $material?->measurementUnit?->name,
                (float) $balance->available_quantity,
                (float) $balance->reserved_quantity,
                $balance->last_movement_at?->format('d.m.Y H:i'),
            ]], null, "A{$row}");
            $row++;
        }

        $this->styleSheet($sheet, 'A', 'G', max(1, $row - 1));
    }

    private function fillSummarySheet(Spreadsheet $spreadsheet, int $organizationId, array $filters): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->sheetTitle(trans_message('basic_warehouse.custody.export.summary_title')));
        $headers = [
            trans_message('basic_warehouse.custody.export.headers.responsible'),
            trans_message('basic_warehouse.custody.export.headers.projects_count'),
            trans_message('basic_warehouse.custody.export.headers.materials_count'),
            trans_message('basic_warehouse.custody.export.headers.positions_count'),
            trans_message('basic_warehouse.custody.export.headers.available_quantity'),
            trans_message('basic_warehouse.custody.export.headers.reserved_quantity'),
            trans_message('basic_warehouse.custody.export.headers.last_movement_at'),
        ];

        $sheet->fromArray([$headers], null, 'A1');

        $payload = $this->custodyService->getSummary(
            $organizationId,
            $filters['project_id'] ?? null,
            $filters['responsible_user_id'] ?? null,
            $filters['material_id'] ?? null,
            $filters['search'] ?? null
        );

        $rows = collect($payload['rows'] ?? []);
        $row = 2;
        foreach ($rows as $summaryRow) {
            $sheet->fromArray([[
                $summaryRow['responsible_user_name'] ?? null,
                $summaryRow['projects_count'] ?? 0,
                $summaryRow['materials_count'] ?? 0,
                $summaryRow['positions_count'] ?? 0,
                $summaryRow['total_quantity'] ?? 0,
                $summaryRow['reserved_quantity'] ?? 0,
                $this->formatSummaryDate($summaryRow['last_movement_at'] ?? null),
            ]], null, "A{$row}");
            $row++;
        }

        $this->styleSheet($sheet, 'A', 'G', max(1, $row - 1));
    }

    private function saveSpreadsheet(Spreadsheet $spreadsheet, int $organizationId, string $mode): string
    {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        if (! is_string($content) || $content === '') {
            throw new RuntimeException(trans_message('basic_warehouse.custody.errors.export_failed'));
        }

        $path = sprintf(
            'org-%d/exports/warehouse/custody/custody_%s_%s.xlsx',
            $organizationId,
            $mode,
            now()->format('Ymd_His_u')
        );

        $stored = $this->fileService->disk()->put($path, $content, 'private');

        if ($stored === false) {
            throw new RuntimeException(trans_message('basic_warehouse.custody.errors.export_failed'));
        }

        return $path;
    }

    private function styleSheet(Worksheet $sheet, string $firstColumn, string $lastColumn, int $lastRow): void
    {
        $headerRange = "{$firstColumn}1:{$lastColumn}1";
        $tableRange = "{$firstColumn}1:{$lastColumn}{$lastRow}";

        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle($headerRange)->getFill()->getStartColor()->setRGB('EAF2FF');
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($tableRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        foreach (range($firstColumn, $lastColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function formatSummaryDate(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        return CarbonImmutable::parse($date)->format('d.m.Y H:i');
    }

    private function sheetTitle(string $title): string
    {
        return mb_substr($title, 0, 31);
    }
}
