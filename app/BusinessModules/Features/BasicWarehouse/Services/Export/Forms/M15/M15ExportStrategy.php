<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export\Forms\M15;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\Strategies\BaseWarehouseExportStrategy;
use App\BusinessModules\Features\BasicWarehouse\Services\Export\WarehouseDocumentPersonResolver;
use App\Helpers\NumberToWordsHelper;
use App\Services\Storage\FileService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class M15ExportStrategy extends BaseWarehouseExportStrategy
{
    private const FIRST_PAGE_ROWS = 16;
    private const CONTINUATION_ROWS = 20;
    private const CONTINUATION_BLOCK_HEIGHT = 25;

    public function __construct(
        FileService $fileService,
        private readonly WarehouseDocumentPersonResolver $personResolver,
    ) {
        parent::__construct($fileService);
    }

    public function export($movementOrCollection): string
    {
        $movements = $this->movements($movementOrCollection);
        $first = $movements->first();
        $spreadsheet = $this->createWorkbook($movements);
        $filename = 'M15_'.$this->documentFileSuffix(
            $first->movementDocumentNumber(), $first->movement_date,
        ).'.xlsx';

        return $this->saveSpreadsheetToS3($spreadsheet, 'exports/warehouse/m15/'.$filename, $first->organization);
    }

    public function getSupportedType(): string
    {
        return 'm15';
    }

    public function createWorkbook($movementOrCollection): Spreadsheet
    {
        $movements = $this->movements($movementOrCollection);
        /** @var WarehouseMovement $first */
        $first = $movements->first();
        $spreadsheet = IOFactory::load(resource_path('templates/warehouse/m15.xlsx'));
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getProperties()->setCreator('МОСТ')->setLastModifiedBy('МОСТ');
        $sheet->setShowGridlines(false);
        $sheet->getHeaderFooter()->setOddFooter('')->setEvenFooter('');
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if (is_string($cell->getValue()) && trim($cell->getValue()) === '') {
                $cell->setValue(null);
            }
        }

        $extraPages = max(0, (int) ceil(($movements->count() - self::FIRST_PAGE_ROWS) / self::CONTINUATION_ROWS) - 1);
        $footerOffset = $extraPages * self::CONTINUATION_BLOCK_HEIGHT;
        if ($extraPages > 0) {
            $sheet->insertNewRowBefore(63, $footerOffset);
            for ($page = 1; $page <= $extraPages; $page++) {
                $this->copyContinuation($sheet, 38 + $page * self::CONTINUATION_BLOCK_HEIGHT);
                $sheet->setBreak('A'.(62 + ($page - 1) * self::CONTINUATION_BLOCK_HEIGHT), Worksheet::BREAK_ROW);
            }
        }

        $person = $first->user === null ? '' : $this->personResolver->resolve(
            $first->user, (int) $first->organization_id, $first->movement_date,
        );
        $this->fillHeader($sheet, $first, $person);
        $total = BigDecimal::zero()->toScale(2);
        $totalKnown = true;
        foreach ($movements as $index => $movement) {
            $row = $index < self::FIRST_PAGE_ROWS
                ? 21 + $index
                : 43 + intdiv($index - self::FIRST_PAGE_ROWS, self::CONTINUATION_ROWS) * self::CONTINUATION_BLOCK_HEIGHT
                    + ($index - self::FIRST_PAGE_ROWS) % self::CONTINUATION_ROWS;
            $amount = $this->fillMaterial($sheet, $row, $movement);
            if ($amount === null) {
                $totalKnown = false;
            } else {
                $total = $total->plus($amount);
            }
        }
        $this->fillFooter($sheet, $movements, $totalKnown ? $total : null, $footerOffset, $person);
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)
            ->setPrintArea('A1:AW'.(75 + $footerOffset));
        $sheet->setBreak('A36', Worksheet::BREAK_ROW);

        return $spreadsheet;
    }

    /** @return Collection<int, WarehouseMovement> */
    private function movements(mixed $input): Collection
    {
        $movements = $input instanceof Collection ? $input->values() : collect([$input]);
        $first = $movements->first();
        if (! $first instanceof WarehouseMovement) {
            throw new InvalidArgumentException('warehouse_m15_movements_required');
        }
        foreach ($movements as $movement) {
            if (! $movement instanceof WarehouseMovement || (int) $movement->organization_id !== (int) $first->organization_id) {
                throw new InvalidArgumentException('warehouse_m15_organization_mismatch');
            }
        }

        return $movements;
    }

    private function fillHeader(Worksheet $sheet, WarehouseMovement $movement, string $person): void
    {
        $metadata = $movement->metadata ?? [];
        $recipient = $metadata['contractor_name'] ?? $movement->toWarehouse?->name ?? $metadata['recipient'] ?? '';
        $reason = $movement->reason ?? $metadata['reason'] ?? '';
        if ($movement->isContractorTransferOut()) {
            $reason = ($reason === '' ? '' : rtrim($reason, ". \n\r").'. ')
                .trans_message('warehouse_basic.tolling_document_note');
        }
        $this->text($sheet, 'A6', 'НАКЛАДНАЯ N '.$this->documentNumber($movement->movementDocumentNumber()));
        $this->text($sheet, 'F9', $movement->organization->legal_name ?? $movement->organization->name);
        $this->text($sheet, 'AR8', '0315007');
        $this->text($sheet, 'AR9', $movement->organization->okpo);
        $sheet->getStyle('AR8:AW9')->getNumberFormat()->setFormatCode('@');
        $sheet->setCellValue('F13', Date::PHPToExcel($movement->movement_date));
        $sheet->getStyle('F13')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        foreach ([
            'K13' => $metadata['operation_code'] ?? null,
            'O13' => $movement->warehouse?->name,
            'T13' => $metadata['sender_activity'] ?? null,
            'Y13' => $recipient,
            'AD13' => $metadata['recipient_activity'] ?? null,
            'AI13' => $metadata['supply_department'] ?? null,
            'AN13' => $metadata['supply_activity'] ?? null,
            'AS13' => $metadata['executor_code'] ?? null,
            'E15' => $reason,
            'C16' => $recipient,
            'AE16' => $person,
        ] as $cell => $value) {
            $this->text($sheet, $cell, $value);
        }
        foreach ([
            'F9' => 110, 'E15' => 130, 'C16' => 72, 'AE16' => 66,
            'O13' => 16, 'T13' => 16, 'Y13' => 16, 'AD13' => 16,
            'AI13' => 16, 'AN13' => 16, 'AS13' => 16,
        ] as $cell => $characters) {
            $this->fitTextRow($sheet, $cell, $characters);
        }
    }

    private function fillMaterial(Worksheet $sheet, int $row, WarehouseMovement $movement): ?BigDecimal
    {
        $metadata = $movement->metadata ?? [];
        $material = $movement->material;
        $amount = $movement->price === null ? null : BigDecimal::of((string) $movement->quantity)
            ->multipliedBy((string) $movement->price)->toScale(2, RoundingMode::HALF_UP);
        foreach ([
            'A' => $material->accounting_account,
            'D' => $metadata['analytic_account_code'] ?? null,
            'G' => $material->name,
            'M' => $material->code,
            'P' => $metadata['okei_code'] ?? null,
            'R' => $material->measurementUnit?->short_name ?? $material->measurementUnit?->name,
            'AN' => $metadata['inventory_number'] ?? null,
            'AQ' => $metadata['passport_number'] ?? null,
            'AT' => $metadata['warehouse_card_number'] ?? null,
        ] as $column => $value) {
            $this->text($sheet, $column.$row, $value);
        }
        foreach (['V', 'Y'] as $column) {
            $sheet->setCellValue($column.$row, (float) $movement->quantity);
            $scale = BigDecimal::of((string) $movement->quantity)->strippedOfTrailingZeros()->getScale();
            $sheet->getStyle($column.$row)->getNumberFormat()->setFormatCode($scale > 0 ? '0.'.str_repeat('0', $scale) : '0');
        }
        foreach (['AB' => $amount === null ? null : (float) $movement->price, 'AE' => $amount === null ? null : (float) (string) $amount, 'AK' => $amount === null ? null : (float) (string) $amount] as $column => $value) {
            $sheet->setCellValue($column.$row, $value);
            $sheet->getStyle($column.$row)->getNumberFormat()->setFormatCode('0.00');
            $sheet->getStyle($column.$row)->getAlignment()->setShrinkToFit(true);
        }
        // Давальческая передача сохраняет учётную оценку, без расчёта НДС по условной ставке.
        $this->text($sheet, 'AH'.$row, '—');
        foreach (['A' => 9, 'D' => 9, 'G' => 22, 'M' => 11, 'P' => 6, 'R' => 13, 'AN' => 11, 'AQ' => 11, 'AT' => 13] as $column => $characters) {
            $this->fitTextRow($sheet, $column.$row, $characters);
        }

        return $amount;
    }

    /** @param Collection<int, WarehouseMovement> $movements */
    private function fillFooter(Worksheet $sheet, Collection $movements, ?BigDecimal $total, int $offset, string $person): void
    {
        $count = $movements->pluck('material_id')->unique()->count();
        $this->text($sheet, 'G'.(64 + $offset), NumberToWordsHelper::integerToWords($count, true));
        $minor = $total?->multipliedBy(100)->toInt();
        $this->text($sheet, 'E'.(66 + $offset), $minor === null ? '' : NumberToWordsHelper::integerToWords(intdiv($minor, 100)));
        $this->text($sheet, 'AD'.(66 + $offset), $minor === null ? '' : sprintf('%02d', $minor % 100));
        $this->text($sheet, 'AO'.(66 + $offset), '—');
        $this->text($sheet, 'AU'.(66 + $offset), '—');
        $this->text($sheet, 'AH'.(72 + $offset), $person);
        $this->fitTextRow($sheet, 'AH'.(72 + $offset), 60);
        $this->fitTextRow($sheet, 'E'.(66 + $offset), 62);
    }

    private function copyContinuation(Worksheet $sheet, int $start): void
    {
        $shift = $start - 38;
        for ($sourceRow = 38; $sourceRow <= 62; $sourceRow++) {
            $sheet->getRowDimension($sourceRow + $shift)->setRowHeight($sheet->getRowDimension($sourceRow)->getRowHeight());
            for ($column = 1; $column <= 49; $column++) {
                $source = Coordinate::stringFromColumnIndex($column).$sourceRow;
                $target = Coordinate::stringFromColumnIndex($column).($sourceRow + $shift);
                $sheet->duplicateStyle($sheet->getStyle($source), $target);
                $cell = $sheet->getCell($source);
                $sheet->setCellValueExplicit($target, $cell->getValue(), $cell->getDataType());
            }
        }
        foreach (array_values($sheet->getMergeCells()) as $range) {
            [$first, $last] = Coordinate::rangeBoundaries($range);
            if ($first[1] >= 38 && $last[1] <= 62) {
                $sheet->mergeCells(Coordinate::stringFromColumnIndex($first[0]).($first[1] + $shift).':'
                    .Coordinate::stringFromColumnIndex($last[0]).($last[1] + $shift));
            }
        }
    }

    private function text(Worksheet $sheet, string $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, is_scalar($value) ? (string) $value : '', DataType::TYPE_STRING);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
    }

    private function fitTextRow(Worksheet $sheet, string $cell, int $characters): void
    {
        $text = (string) $sheet->getCell($cell)->getValue();
        $lines = array_sum(array_map(
            static fn (string $line): int => max(1, (int) ceil(mb_strlen($line) / $characters)),
            preg_split('/\R/u', $text) ?: [''],
        ));
        $row = (int) Coordinate::coordinateFromString($cell)[1];
        $height = max($sheet->getRowDimension($row)->getRowHeight(), 13.5, $lines * 12);
        $sheet->getRowDimension($row)->setRowHeight($height);
    }
}
