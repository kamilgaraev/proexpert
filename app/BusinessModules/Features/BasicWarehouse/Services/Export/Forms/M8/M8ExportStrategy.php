<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M8;

use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\Services\Storage\FileService;
use App\Support\OrganizationBusinessTimezone;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Стратегия экспорта Лимитно-заборной карты (Форма № М-8)
 */
class M8ExportStrategy extends BaseWarehouseExportStrategy
{
    public function __construct(
        FileService $fileService,
        private readonly OrganizationBusinessTimezone $businessTimezone,
    ) {
        parent::__construct($fileService);
    }

    /**
     * @param  array{reservation: AssetReservation, movements: Collection<int, WarehouseMovement>}  $data
     */
    public function export($data): string
    {
        /** @var AssetReservation $reservation */
        $reservation = $data['reservation'];
        $movements = $data['movements'];

        $reservation->loadMissing(['organization', 'material.measurementUnit']);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $this->setHeader($sheet, $reservation);
        $lastRow = $this->setTable($sheet, $reservation, $movements);
        $this->applyStyles($sheet, $lastRow);

        $materialCode = $this->filenameFragment(
            $reservation->material?->code ?? $reservation->material?->name,
            'material'
        );
        $filename = 'M8_'.$materialCode.'_'.$this->documentFileSuffix(
            $reservation->metadata['document_number'] ?? null,
            $reservation->reserved_at ?? $reservation->created_at
        ).'.xlsx';
        $path = "exports/warehouse/m8/{$filename}";

        return $this->saveSpreadsheetToS3($spreadsheet, $path, $reservation->organization_id);
    }

    public function getSupportedType(): string
    {
        return 'm8';
    }

    protected function setHeader(Worksheet $sheet, AssetReservation $reservation): void
    {
        $org = $reservation->organization;

        foreach (range(1, 3) as $row) {
            $sheet->mergeCells("J{$row}:L{$row}");
        }
        $sheet->setCellValue('J1', 'Унифицированная форма № М-8');
        $sheet->setCellValue('J2', 'Утверждена постановлением Госкомстата');
        $sheet->setCellValue('J3', 'России от 30.10.97 № 71а');
        $sheet->getStyle('J1:L3')->getFont()->setSize(8);
        $sheet->getStyle('J1:L3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
            ->setWrapText(true);

        $sheet->mergeCells('A5:I5');
        $sheet->setCellValue('A5', $org->legal_name ?? $org->name);
        $this->setUnderline($sheet, 'A5:I5');
        $sheet->mergeCells('A6:I6');
        $sheet->setCellValue('A6', 'организация');
        $this->setCenter($sheet, 'A6:I6');
        $sheet->getStyle('A6')->getFont()->setSize(8);

        $sheet->mergeCells('J5:K5');
        $sheet->mergeCells('J6:K6');
        $sheet->mergeCells('J7:K7');
        $sheet->setCellValue('J5', 'Код');
        $sheet->setCellValue('J6', 'Форма по ОКУД');
        $sheet->setCellValue('L6', '0315005');
        $sheet->setCellValue('J7', 'по ОКПО');
        $sheet->setCellValue('L7', $org->okpo ?? '');
        $this->applyTableStyle($sheet, 'J5:L7');
        $this->setCenter($sheet, 'J5:L7');

        $title = 'ЛИМИТНО-ЗАБОРНАЯ КАРТА № '
            .$this->documentNumber($reservation->metadata['document_number'] ?? null);
        $sheet->mergeCells('A9:L9');
        $sheet->setCellValue('A9', $title);
        $this->setBold($sheet, 'A9');
        $this->setCenter($sheet, 'A9');
        $sheet->getStyle('A9')->getFont()->setSize(14);
        $sheet->getStyle('A9')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(9)->setRowHeight($this->titleRowHeight($title));

        $sheet->mergeCells('A11:L11');
        $sheet->mergeCells('A12:L12');
        $unit = $reservation->material?->measurementUnit;
        $sheet->setCellValue('A11', 'Материал: '.($reservation->material?->name ?? ''));
        $sheet->setCellValue(
            'A12',
            'Лимит: '.$reservation->quantity.' '.($unit?->short_name ?? $unit?->name ?? '')
        );
    }

    protected function setTable(Worksheet $sheet, AssetReservation $reservation, Collection $movements): int
    {
        $row = 15;
        $this->mergeTableRow($sheet, $row);
        $sheet->setCellValue("A{$row}", 'Дата');
        $sheet->setCellValue("C{$row}", 'Номер документа');
        $sheet->setCellValue("G{$row}", 'Отпущено');
        $sheet->setCellValue("J{$row}", 'Остаток лимита');

        $this->setBold($sheet, "A{$row}:L{$row}");
        $this->setCenter($sheet, "A{$row}:L{$row}");

        $remaining = (float) $reservation->quantity;
        $timezone = $this->businessTimezone->resolve($reservation->organization);
        foreach ($movements as $m) {
            $row++;
            $this->mergeTableRow($sheet, $row);
            $sheet->setCellValue("A{$row}", $m->movement_date->copy()->setTimezone($timezone)->format('d.m.Y'));
            $sheet->setCellValue("C{$row}", $this->documentNumber($m->document_number));
            $sheet->setCellValue("G{$row}", $m->quantity);
            $remaining -= (float) $m->quantity;
            $sheet->setCellValue("J{$row}", max(0, $remaining));
        }

        $this->applyTableStyle($sheet, "A15:L{$row}");
        if ($row > 15) {
            $this->setCenter($sheet, "A16:B{$row}");
            $this->setCenter($sheet, "G16:L{$row}");
        }

        return $row;
    }

    protected function applyStyles(Worksheet $sheet, int $lastRow): void
    {
        foreach (range('A', 'L') as $column) {
            $sheet->getColumnDimension($column)->setWidth(10);
        }
        foreach (['C', 'D', 'E', 'F'] as $column) {
            $sheet->getColumnDimension($column)->setWidth(14);
        }

        $sheet->getStyle("A1:L{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A15:L{$lastRow}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(15)->setRowHeight(30);

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setPrintArea("A1:L{$lastRow}");
        $sheet->getPageMargins()
            ->setTop(0.35)
            ->setRight(0.3)
            ->setBottom(0.35)
            ->setLeft(0.3);
    }

    private function mergeTableRow(Worksheet $sheet, int $row): void
    {
        $sheet->mergeCells("A{$row}:B{$row}");
        $sheet->mergeCells("C{$row}:F{$row}");
        $sheet->mergeCells("G{$row}:I{$row}");
        $sheet->mergeCells("J{$row}:L{$row}");
    }

    private function titleRowHeight(string $title): float
    {
        $lineCount = max(1, (int) ceil(mb_strwidth($title, 'UTF-8') / 70));

        return max(24, $lineCount * 21);
    }
}
