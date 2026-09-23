<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class WarehouseStockExportService
{
    private const MAX_XLSX_ROWS = 5000;

    private const MAX_PDF_ROWS = 1000;

    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly FileService $fileService,
    ) {}

    public function export(
        OrganizationWarehouse $warehouse,
        int $organizationId,
        int $userId,
        array $filters,
        string $format,
    ): array {
        $limit = $format === 'pdf' ? self::MAX_PDF_ROWS : self::MAX_XLSX_ROWS;
        $rows = $this->warehouseService
            ->stockExportQuery($organizationId, ['warehouse_id' => $warehouse->id, ...$filters])
            ->limit($limit + 1)
            ->get();

        if ($rows->count() > $limit) {
            throw new DomainException(trans_message('basic_warehouse.warehouse.stock_export_too_large', [
                'limit' => $limit,
            ]));
        }

        $content = $format === 'pdf'
            ? $this->renderPdf($warehouse, $rows->all())
            : $this->renderXlsx($warehouse, $rows->all());
        $filename = sprintf('ostatki-sklada-%d-%s.%s', $warehouse->id, now()->format('Y-m-d'), $format);
        $path = OrganizationStoragePath::forDomain(
            $organizationId,
            'warehouse',
            "exports/user-{$userId}/stock",
            sprintf('ostatki-sklada-%d-%s-%s', $warehouse->id, now()->format('Y-m-d'), Str::uuid()),
            $format,
        );
        $stored = $this->fileService->putPrivate(
            $path,
            $content,
            $format === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            hash('sha256', $content),
        );

        return [
            'url' => $this->fileService->temporaryDownloadUrl($stored->key, 15 * 60),
            'filename' => $filename,
        ];
    }

    private function renderXlsx(OrganizationWarehouse $warehouse, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Остатки');
        $sheet->setCellValue('A1', 'Остатки склада: '.$warehouse->name);
        $sheet->mergeCells('A1:J1');
        $sheet->setCellValue('A2', 'Сформировано: '.now()->format('d.m.Y H:i'));
        $sheet->mergeCells('A2:J2');
        $sheet->fromArray([[
            'Позиция', 'Код', 'Ед. изм.', 'Доступно', 'Резерв', 'Всего',
            'Средняя цена, ₽', 'Стоимость, ₽', 'Адрес', 'Состояние',
        ]], null, 'A4');
        $sheet->getStyle('A1:J1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A4:J4')->getFont()->setBold(true);
        $sheet->getStyle('A4:J4')->getFill()->setFillType('solid')->getStartColor()->setRGB('EAF2FF');

        foreach ($rows as $index => $item) {
            $row = $index + 5;
            $available = (float) $item->available_quantity;
            $reserved = (float) $item->reserved_quantity;
            $total = $available + $reserved;
            $value = (float) $item->total_value;
            foreach ([
                'A' => $item->material_name,
                'B' => $item->material_code,
                'C' => $item->unit_short_name ?: $item->unit_name,
                'I' => $item->storage_address,
                'J' => $this->isLowStock($item) ? 'Низкий остаток' : '',
            ] as $column => $text) {
                $sheet->setCellValueExplicit("{$column}{$row}", (string) ($text ?? ''), DataType::TYPE_STRING);
            }
            $sheet->fromArray([[
                $available,
                $reserved,
                $total,
                $total > 0 ? $value / $total : 0,
                $value,
            ]], null, "D{$row}");
        }

        $lastRow = max(5, count($rows) + 4);
        $sheet->getStyle("D5:H{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->freezePane('A5');
        $sheet->setAutoFilter("A4:J{$lastRow}");
        foreach (['A' => 45, 'B' => 20, 'C' => 12, 'D' => 16, 'E' => 16,
            'F' => 16, 'G' => 20, 'H' => 20, 'I' => 35, 'J' => 20] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $level = ob_get_level();
        ob_start();
        try {
            (new Xlsx($spreadsheet))->save('php://output');
            $content = ob_get_clean();
        } finally {
            if (ob_get_level() > $level) {
                ob_end_clean();
            }
            $spreadsheet->disconnectWorksheets();
        }

        if (! is_string($content) || $content === '') {
            throw new RuntimeException('warehouse_stock_xlsx_render_failed');
        }

        return $content;
    }

    private function renderPdf(OrganizationWarehouse $warehouse, array $rows): string
    {
        return Pdf::loadView('warehouse.exports.stock-balances', [
            'warehouse' => $warehouse,
            'rows' => $rows,
            'generatedAt' => now()->format('d.m.Y H:i'),
            'totalValue' => array_sum(array_map(static fn ($row): float => (float) $row->total_value, $rows)),
        ])->setPaper('a4', 'landscape')->output();
    }

    private function isLowStock(object $row): bool
    {
        return (float) $row->min_stock_level > 0
            && (float) $row->available_quantity <= (float) $row->min_stock_level;
    }
}
