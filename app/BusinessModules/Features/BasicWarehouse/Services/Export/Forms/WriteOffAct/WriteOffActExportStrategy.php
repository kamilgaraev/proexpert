<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\WriteOffAct;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class WriteOffActExportStrategy extends BaseWarehouseExportStrategy
{
    public function export($movementOrCollection): string
    {
        $movements = $movementOrCollection instanceof Collection
            ? $movementOrCollection
            : collect([$movementOrCollection]);
        $firstMovement = $movements->first();

        if (! $firstMovement instanceof WarehouseMovement) {
            throw new InvalidArgumentException('write_off_act_requires_movement');
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Акт списания');

        $this->setHeader($sheet, $firstMovement);
        $lastItemRow = $this->setTable($sheet, $movements);
        $this->setFooter($sheet, $firstMovement, $lastItemRow);
        $this->applyStyles($sheet, $lastItemRow);

        $fileSuffix = $this->documentFileSuffix(
            $firstMovement->document_number,
            $firstMovement->movement_date
        );
        $filename = 'akt-spisaniya_'.$fileSuffix.'.xlsx';

        return $this->saveSpreadsheetToS3(
            $spreadsheet,
            "exports/warehouse/write-off-acts/{$filename}",
            $firstMovement->organization
        );
    }

    public function getSupportedType(): string
    {
        return 'write_off_act';
    }

    private function setHeader(Worksheet $sheet, WarehouseMovement $movement): void
    {
        $organizationName = $movement->organization->legal_name
            ?? $movement->organization->name;
        $documentNumber = $this->documentNumber($movement->document_number);

        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', $organizationName);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('E2:G2');
        $sheet->setCellValue('E2', 'УТВЕРЖДАЮ');
        $sheet->mergeCells('E3:G3');
        $sheet->setCellValue('E3', 'Руководитель ____________________');

        $sheet->mergeCells('A4:G4');
        $sheet->setCellValue('A4', 'АКТ № '.$documentNumber);
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', 'о списании материальных ценностей');
        $sheet->getStyle('A5')->getFont()->setBold(true);
        $sheet->getStyle('A5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->mergeCells('A7:G7');
        $sheet->setCellValue('A7', 'Дата составления: '.$movement->movement_date->format('d.m.Y'));
        $sheet->mergeCells('A8:G8');
        $sheet->setCellValue('A8', 'Склад: '.($movement->warehouse->name ?? 'Не указан'));
        $sheet->mergeCells('A9:G9');
        $sheet->setCellValue('A9', 'Объект: '.($movement->project->name ?? 'Не указан'));
        $sheet->mergeCells('A10:G10');
        $sheet->setCellValue('A10', 'Основание списания: '.($movement->reason ?: 'Не указано'));
    }

    private function setTable(Worksheet $sheet, Collection $movements): int
    {
        $headerRow = 13;
        $sheet->setCellValue("A{$headerRow}", '№');
        $sheet->setCellValue("B{$headerRow}", 'Материальная ценность');
        $sheet->setCellValue("C{$headerRow}", 'Ед. изм.');
        $sheet->setCellValue("D{$headerRow}", 'Количество');
        $sheet->setCellValue("E{$headerRow}", 'Цена, руб.');
        $sheet->setCellValue("F{$headerRow}", 'Сумма, руб.');
        $sheet->setCellValue("G{$headerRow}", 'Причина');

        $row = $headerRow;
        foreach ($movements->values() as $index => $movement) {
            if (! $movement instanceof WarehouseMovement) {
                continue;
            }

            $row++;
            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $movement->material->name);
            $sheet->setCellValue("C{$row}", $movement->material->measurementUnit->short_name
                ?? $movement->material->measurementUnit->name
                ?? '');
            $sheet->setCellValue("D{$row}", (float) $movement->quantity);
            $sheet->setCellValue("E{$row}", (float) $movement->price);
            $sheet->setCellValue("F{$row}", round((float) $movement->quantity * (float) $movement->price, 2));
            $sheet->setCellValue("G{$row}", $movement->operationCategoryLabel() ?? 'Списание');
        }

        $totalRow = $row + 1;
        $sheet->mergeCells("A{$totalRow}:E{$totalRow}");
        $sheet->setCellValue("A{$totalRow}", 'Итого');
        $sheet->setCellValue("F{$totalRow}", round($movements->sum(
            static fn (WarehouseMovement $movement): float => (float) $movement->quantity * (float) $movement->price
        ), 2));

        $this->applyTableStyle($sheet, "A{$headerRow}:G{$totalRow}");
        $this->setBold($sheet, "A{$headerRow}:G{$headerRow}");
        $this->setBold($sheet, "A{$totalRow}:G{$totalRow}");
        $this->setCenter($sheet, "A{$headerRow}:G{$headerRow}");

        return $row;
    }

    private function setFooter(Worksheet $sheet, WarehouseMovement $movement, int $lastItemRow): void
    {
        $row = $lastItemRow + 4;
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue(
            "A{$row}",
            'Заключение комиссии: материальные ценности подлежат списанию. Основание: '.($movement->reason ?: 'не указано').'.'
        );

        $row += 2;
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue("A{$row}", 'Председатель комиссии: ____________________ / ____________________ /');
        $row++;
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue("A{$row}", 'Член комиссии: ____________________________ / ____________________ /');
        $row++;
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue("A{$row}", 'Член комиссии: ____________________________ / ____________________ /');
        $row += 2;
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue(
            "A{$row}",
            'Материально ответственное лицо: ____________________ / '.($movement->user->name ?? '____________________').' /'
        );
        $row += 2;
        $sheet->mergeCells("A{$row}:G{$row}");
        $sheet->setCellValue(
            "A{$row}",
            'Форма и состав подписантов утверждаются руководителем организации в учетной политике.'
        );
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->setSize(8);
    }

    private function applyStyles(Worksheet $sheet, int $lastItemRow): void
    {
        $sheet->getColumnDimension('A')->setWidth(6);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(14);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(17);
        $sheet->getColumnDimension('G')->setWidth(28);
        $sheet->getStyle('A1:G35')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A7:G35')->getAlignment()->setWrapText(true);
        $sheet->getStyle('D14:F'.($lastItemRow + 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);
        $sheet->getPageMargins()->setTop(0.4)->setBottom(0.4)->setLeft(0.4)->setRight(0.4);
    }
}
