<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Services;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

use function trans_message;

final class BudgetImportFileReader
{
    /**
     * @return array{format:string, rows:list<array<string, mixed>>}
     */
    public function readUploaded(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'txt') {
            $extension = 'csv';
        }

        return $this->readPath($file->getRealPath() ?: $file->getPathname(), $extension);
    }

    /**
     * @return array{format:string, rows:list<array<string, mixed>>}
     */
    public function readPath(string $path, string $extension): array
    {
        $extension = strtolower($extension);

        $reader = match ($extension) {
            'csv' => $this->csvReader($path),
            'xlsx' => $this->xlsxReader(),
            default => throw new InvalidArgumentException(trans_message('budgeting.errors.import_format_unsupported')),
        };

        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $this->worksheetRows($worksheet);
        $spreadsheet->disconnectWorksheets();

        return [
            'format' => $extension,
            'rows' => $rows,
        ];
    }

    private function xlsxReader(): Xlsx
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);

        return $reader;
    }

    private function csvReader(string $path): IReader
    {
        $reader = new Csv();
        $reader->setInputEncoding('UTF-8');
        $reader->setDelimiter($this->detectCsvDelimiter($path));
        $reader->setEnclosure('"');
        $reader->setSheetIndex(0);

        return $reader;
    }

    private function detectCsvDelimiter(string $path): string
    {
        $line = '';
        $handle = fopen($path, 'rb');

        if (is_resource($handle)) {
            $line = (string) fgets($handle);
            fclose($handle);
        }

        return substr_count($line, ';') > substr_count($line, ',') ? ';' : ',';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function worksheetRows(Worksheet $worksheet): array
    {
        $highestRow = $worksheet->getHighestDataRow();
        $highestColumnIndex = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());
        $headers = [];
        $rows = [];

        for ($column = 1; $column <= $highestColumnIndex; $column++) {
            $headers[$column] = $this->normalizeHeader((string) $worksheet->getCell([$column, 1])->getCalculatedValue());
        }

        for ($row = 2; $row <= $highestRow; $row++) {
            $payload = ['row_number' => $row];
            $hasValues = false;

            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $header = $headers[$column] ?? null;

                if ($header === null || $header === '') {
                    continue;
                }

                $value = $worksheet->getCell([$column, $row])->getCalculatedValue();
                if ($value !== null && trim((string) $value) !== '') {
                    $hasValues = true;
                }

                $payload[$header] = is_string($value) ? trim($value) : $value;
            }

            if ($hasValues) {
                $rows[] = $payload;
            }
        }

        return $rows;
    }

    private function normalizeHeader(string $header): string
    {
        $value = mb_strtolower(trim($header));
        $value = str_replace(["\u{FEFF}", ' ', '-', '.'], ['', '_', '_', '_'], $value);

        $aliases = [
            'статья' => 'article_code',
            'код_статьи' => 'article_code',
            'article' => 'article_code',
            'budget_article_code' => 'article_code',
            'цфо' => 'cfo_code',
            'код_цфо' => 'cfo_code',
            'responsibility_center_code' => 'cfo_code',
            'period' => 'month',
            'период' => 'month',
            'месяц' => 'month',
            'сумма' => 'plan_amount',
            'план' => 'plan_amount',
            'plan' => 'plan_amount',
            'forecast' => 'forecast_amount',
            'прогноз' => 'forecast_amount',
            'валюта' => 'currency',
            'проект' => 'project_id',
            'договор' => 'contract_id',
            'контрагент' => 'counterparty_id',
            'описание' => 'description',
            'комментарий' => 'description',
            'сценарий' => 'scenario_code',
            'scenario' => 'scenario_code',
            'версия' => 'version_uuid',
            'version' => 'version_uuid',
            'тип_бюджета' => 'budget_kind',
            'budget_kind' => 'budget_kind',
        ];

        return $aliases[$value] ?? $value;
    }
}
