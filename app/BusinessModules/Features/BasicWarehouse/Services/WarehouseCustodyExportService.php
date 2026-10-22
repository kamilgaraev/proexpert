<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
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

    public function export(int $organizationId, array $filters, string $mode, int $userId): CurrentStoredFile
    {
        $mode = $this->normalizeMode($mode);
        $spreadsheet = new Spreadsheet;

        if ($mode === self::MODE_DETAIL) {
            $this->fillDetailSheet($spreadsheet, $organizationId, $filters);
        } else {
            $this->fillSummarySheet($spreadsheet, $organizationId, $filters);
        }

        return $this->saveSpreadsheet($spreadsheet, $organizationId, $userId, $mode);
    }

    public function temporaryUrl(
        int $organizationId,
        int $userId,
        string $path,
        int $minutes = 15,
    ): string {
        $pattern = sprintf(
            '#^org-%d/warehouse/exports/user-%d/custody/(?:detail|summary)/[^/]+\.xlsx$#D',
            $organizationId,
            $userId,
        );

        if (
            $organizationId < 1
            || $userId < 1
            || $minutes < 1
            || $minutes > 60
            || preg_match($pattern, $path) !== 1
        ) {
            throw new InvalidArgumentException('warehouse_custody_export_path_invalid');
        }

        return $this->fileService->temporaryDownloadUrl($path, $minutes * 60);
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
                $balance->getAttribute('responsible_user_display_name')
                    ?? $balance->warehouse?->responsibleUser?->name,
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

        $this->styleSheet($sheet, 'A', 'G', max(1, $row - 1), true);
    }

    private function saveSpreadsheet(
        Spreadsheet $spreadsheet,
        int $organizationId,
        int $userId,
        string $mode,
    ): CurrentStoredFile {
        $writer = new Xlsx($spreadsheet);

        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();

        if (! is_string($content) || $content === '') {
            throw new RuntimeException(trans_message('basic_warehouse.custody.errors.export_failed'));
        }

        $path = OrganizationStoragePath::forDomain(
            $organizationId,
            'warehouse',
            "exports/user-{$userId}/custody/{$mode}",
            Str::uuid()->toString(),
            'xlsx',
        );

        return $this->fileService->putPrivate(
            $path,
            $content,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            hash('sha256', $content),
        );
    }

    private function styleSheet(Worksheet $sheet, string $firstColumn, string $lastColumn, int $lastRow, bool $summary = false): void
    {
        $headerRange = "{$firstColumn}1:{$lastColumn}1";
        $tableRange = "{$firstColumn}1:{$lastColumn}{$lastRow}";

        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID);
        $sheet->getStyle($headerRange)->getFill()->getStartColor()->setRGB('F2F2F2');
        $sheet->getStyle($tableRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle($tableRange)->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle($tableRange)->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(32);

        $widths = $summary
            ? ['A' => 28, 'B' => 12, 'C' => 12, 'D' => 12, 'E' => 16, 'F' => 16, 'G' => 24]
            : ['A' => 24, 'B' => 26, 'C' => 34, 'D' => 10, 'E' => 14, 'F' => 14, 'G' => 24];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setAutoSize(false)->setWidth($width);
        }
        if ($lastRow > 1) {
            $numericFirstColumn = $summary ? 'B' : 'E';
            $sheet->getStyle("{$numericFirstColumn}2:F{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->freezePane('A2');
        $sheet->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setPrintArea($tableRange)
            ->setRowsToRepeatAtTop([1, 1]);
        $sheet->getPageMargins()->setLeft(0.25)->setRight(0.25)->setTop(0.35)->setBottom(0.35)->setHeader(0.15)->setFooter(0.15);
        $sheet->getHeaderFooter()->setOddFooter('&R&9&P / &N');
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
