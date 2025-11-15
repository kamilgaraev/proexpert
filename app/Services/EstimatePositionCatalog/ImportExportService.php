<?php

namespace App\Services\EstimatePositionCatalog;

use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\WorkType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportExportService
{
    /**
     * Импортировать позиции из Excel
     */
    public function importFromExcel(int $organizationId, UploadedFile $file, int $userId): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Пропустить заголовок
            array_shift($rows);

            $imported = 0;
            $skipped = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2; // +2 потому что пропустили заголовок и индекс с 0

                // Пропустить пустые строки
                if (empty(array_filter($row))) {
                    continue;
                }

                try {
                    $this->importRow($organizationId, $userId, $row);
                    $imported++;
                } catch (\Exception $e) {
                    $skipped++;
                    $errors[] = "Строка {$rowNumber}: {$e->getMessage()}";
                    Log::warning('estimate_position_catalog.import_row_failed', [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('estimate_position_catalog.import_completed', [
                'organization_id' => $organizationId,
                'imported' => $imported,
                'skipped' => $skipped,
                'user_id' => $userId,
            ]);

            return [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            Log::error('estimate_position_catalog.import_failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Импортировать одну строку
     */
    private function importRow(int $organizationId, int $userId, array $row): void
    {
        // Формат: код, название, описание, тип, единица измерения, цена, прямые затраты, накладные %, прибыль %
        [$code, $name, $description, $itemType, $measurementUnitName, $unitPrice, $directCosts, $overheadPercent, $profitPercent] = $row;

        if (empty($code) || empty($name)) {
            throw new \InvalidArgumentException('Код и название обязательны');
        }

        // Найти единицу измерения
        $measurementUnit = MeasurementUnit::where('name', 'ilike', $measurementUnitName)->first();
        
        if (!$measurementUnit) {
            throw new \InvalidArgumentException("Единица измерения '{$measurementUnitName}' не найдена");
        }

        // Проверить, существует ли уже позиция с таким кодом
        $existing = EstimatePositionCatalog::where('organization_id', $organizationId)
            ->where('code', $code)
            ->first();

        if ($existing) {
            // Обновить существующую
            $existing->update([
                'name' => $name,
                'description' => $description,
                'item_type' => $itemType ?: 'work',
                'measurement_unit_id' => $measurementUnit->id,
                'unit_price' => $unitPrice ?: 0,
                'direct_costs' => $directCosts ?: null,
                'overhead_percent' => $overheadPercent ?: null,
                'profit_percent' => $profitPercent ?: null,
            ]);
        } else {
            // Создать новую
            EstimatePositionCatalog::create([
                'organization_id' => $organizationId,
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'item_type' => $itemType ?: 'work',
                'measurement_unit_id' => $measurementUnit->id,
                'unit_price' => $unitPrice ?: 0,
                'direct_costs' => $directCosts ?: null,
                'overhead_percent' => $overheadPercent ?: null,
                'profit_percent' => $profitPercent ?: null,
                'is_active' => true,
                'created_by_user_id' => $userId,
            ]);
        }
    }

    /**
     * Экспортировать позиции в Excel
     */
    public function exportToExcel(int $organizationId, array $filters = []): string
    {
        $positions = $this->getPositionsForExport($organizationId, $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Заголовки
        $sheet->setCellValue('A1', 'Код');
        $sheet->setCellValue('B1', 'Название');
        $sheet->setCellValue('C1', 'Описание');
        $sheet->setCellValue('D1', 'Тип');
        $sheet->setCellValue('E1', 'Ед. изм.');
        $sheet->setCellValue('F1', 'Цена');
        $sheet->setCellValue('G1', 'Прямые затраты');
        $sheet->setCellValue('H1', 'Накладные %');
        $sheet->setCellValue('I1', 'Прибыль %');
        $sheet->setCellValue('J1', 'Категория');
        $sheet->setCellValue('K1', 'Активна');
        $sheet->setCellValue('L1', 'Использований');

        // Данные
        $row = 2;
        foreach ($positions as $position) {
            $sheet->setCellValue('A' . $row, $position->code);
            $sheet->setCellValue('B' . $row, $position->name);
            $sheet->setCellValue('C' . $row, $position->description);
            $sheet->setCellValue('D' . $row, $position->item_type);
            $sheet->setCellValue('E' . $row, $position->measurementUnit->name ?? '');
            $sheet->setCellValue('F' . $row, $position->unit_price);
            $sheet->setCellValue('G' . $row, $position->direct_costs);
            $sheet->setCellValue('H' . $row, $position->overhead_percent);
            $sheet->setCellValue('I' . $row, $position->profit_percent);
            $sheet->setCellValue('J' . $row, $position->category->name ?? '');
            $sheet->setCellValue('K' . $row, $position->is_active ? 'Да' : 'Нет');
            $sheet->setCellValue('L' . $row, $position->usage_count);
            $row++;
        }

        // Стилизация заголовков
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);

        // Автоширина колонок
        foreach (range('A', 'L') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'estimate_positions_') . '.xlsx';
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Экспортировать позиции в CSV
     */
    public function exportToCsv(int $organizationId, array $filters = []): string
    {
        $positions = $this->getPositionsForExport($organizationId, $filters);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Заголовки
        $sheet->fromArray([
            ['Код', 'Название', 'Описание', 'Тип', 'Ед. изм.', 'Цена', 'Прямые затраты', 'Накладные %', 'Прибыль %']
        ], null, 'A1');

        // Данные
        $row = 2;
        foreach ($positions as $position) {
            $sheet->fromArray([[
                $position->code,
                $position->name,
                $position->description,
                $position->item_type,
                $position->measurementUnit->name ?? '',
                $position->unit_price,
                $position->direct_costs,
                $position->overhead_percent,
                $position->profit_percent,
            ]], null, 'A' . $row);
            $row++;
        }

        $writer = new CsvWriter($spreadsheet);
        $writer->setDelimiter(',');
        $writer->setEnclosure('"');
        $writer->setLineEnding("\r\n");
        $writer->setSheetIndex(0);

        $tempFile = tempnam(sys_get_temp_dir(), 'estimate_positions_') . '.csv';
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Сгенерировать шаблон для импорта
     */
    public function generateTemplate(): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Заголовки
        $sheet->setCellValue('A1', 'Код');
        $sheet->setCellValue('B1', 'Название');
        $sheet->setCellValue('C1', 'Описание');
        $sheet->setCellValue('D1', 'Тип (work, material, equipment, labor)');
        $sheet->setCellValue('E1', 'Единица измерения');
        $sheet->setCellValue('F1', 'Цена за единицу');
        $sheet->setCellValue('G1', 'Прямые затраты');
        $sheet->setCellValue('H1', 'Накладные %');
        $sheet->setCellValue('I1', 'Прибыль %');

        // Пример строки
        $sheet->setCellValue('A2', 'POS-001');
        $sheet->setCellValue('B2', 'Монтаж окон');
        $sheet->setCellValue('C2', 'Установка пластиковых окон');
        $sheet->setCellValue('D2', 'work');
        $sheet->setCellValue('E2', 'м2');
        $sheet->setCellValue('F2', '2500.00');
        $sheet->setCellValue('G2', '2000.00');
        $sheet->setCellValue('H2', '15.00');
        $sheet->setCellValue('I2', '10.00');

        // Стилизация
        $sheet->getStyle('A1:I1')->getFont()->setBold(true);
        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'estimate_positions_template_') . '.xlsx';
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Получить позиции для экспорта с учетом фильтров
     */
    private function getPositionsForExport(int $organizationId, array $filters = [])
    {
        $query = EstimatePositionCatalog::where('organization_id', $organizationId)
            ->with(['category', 'measurementUnit']);

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['item_type'])) {
            $query->where('item_type', $filters['item_type']);
        }

        if (!empty($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        return $query->orderBy('code')->get();
    }
}

