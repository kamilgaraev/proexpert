<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Operations;

use App\Enums\EstimatePositionItemType;
use App\Models\EstimateOperationPriceList;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class EstimatePriceListImporter
{
    public function __construct(private readonly EstimateOperationAccess $access, private readonly FileService $files)
    {
    }

    public function inspect(User $actor, int $projectId, UploadedFile $file, int $headerRow = 1): array
    {
        $this->access->project($actor, (int) $actor->current_organization_id, $projectId);
        $rows = $this->read($file, $headerRow + 5);

        return ['headers' => $rows['values'][$headerRow - 1] ?? [], 'sample' => array_slice($rows['values'], $headerRow, 5), 'row_count' => max(0, $rows['row_count'] - $headerRow)];
    }

    public function import(User $actor, int $projectId, UploadedFile $file, array $parameters): EstimateOperationPriceList
    {
        $this->access->project($actor, (int) $actor->current_organization_id, $projectId);
        $entries = $this->entries($this->read($file, 20100, array_values($parameters['columns']))['values'], $parameters['columns'], (int) ($parameters['header_row'] ?? 1));
        $id = (string) Str::uuid();
        $path = 'org-'.$actor->current_organization_id.'/estimate-price-lists/'.$id.'.'.strtolower($file->getClientOriginalExtension());
        $checksum = hash_file('sha256', $file->getPathname());
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false || $checksum === false) {
            $this->invalid();
        }
        try {
            $this->files->putPrivate($path, $stream, $file->getMimeType() ?? 'application/octet-stream', $checksum);
        } finally {
            fclose($stream);
        }

        return EstimateOperationPriceList::query()->create([
            'id' => $id, 'organization_id' => $actor->current_organization_id, 'project_id' => $projectId, 'actor_id' => $actor->id,
            'name' => $parameters['name'], 'currency' => $parameters['currency'], 'vat_rate' => $parameters['vat_rate'],
            'includes_vat' => $parameters['includes_vat'], 'effective_date' => $parameters['effective_date'],
            'file_path' => $path, 'checksum' => $checksum, 'entries' => $entries,
        ]);
    }

    public function entries(array $rows, array $columns, int $headerRow): array
    {
        if (count(array_unique($columns)) !== 5) {
            $this->invalid();
        }
        $entries = [];
        $types = array_column(EstimatePositionItemType::cases(), 'value');
        $aliases = ['работа' => 'work', 'материал' => 'material', 'механизм' => 'machinery', 'машина' => 'machinery', 'труд' => 'labor', 'оборудование' => 'equipment'];
        foreach (array_slice($rows, $headerRow, null, true) as $number => $row) {
            if (implode('', array_map(fn ($v): string => trim((string) $v), $row)) === '') {
                continue;
            }
            $entry = [];
            foreach ($columns as $field => $index) {
                $value = trim((string) ($row[$index] ?? ''));
                if ($value === '' || str_starts_with($value, '=') || mb_strlen($value) > 500) {
                    $this->invalid();
                }
                $entry[$field] = $value;
            }
            $entry['item_type'] = mb_strtolower($entry['item_type']);
            $entry['item_type'] = $aliases[$entry['item_type']] ?? $entry['item_type'];
            $entry['unit_price'] = str_replace(["\u{00A0}", ' ', ','], ['', '', '.'], $entry['unit_price']);
            if (! in_array($entry['item_type'], $types, true) || preg_match('/^\d{1,12}(\.\d{1,4})?$/', $entry['unit_price']) !== 1) {
                $this->invalid();
            }
            $entry['row'] = $number + 1;
            $entries[] = $entry;
        }
        if ($entries === []) {
            $this->invalid();
        }

        return $entries;
    }

    private function read(UploadedFile $file, int $rowLimit = 20100, ?array $columns = null): array
    {
        $type = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'Xlsx' : 'Csv';
        if ($type === 'Xlsx') {
            $zip = new \ZipArchive();
            if ($zip->open($file->getPathname()) !== true) {
                $this->invalid();
            }
            try {
                $bytes = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $bytes += $zip->statIndex($i)['size'] ?? 0;
                    if ($i > 2000 || $bytes > 52428800) {
                        throw ValidationException::withMessages(['file' => trans_message('estimate_operations.too_large')]);
                    }
                }
            } finally {
                $zip->close();
            }
        }
        $reader = IOFactory::createReader($type);
        if ($reader instanceof Csv) {
            $reader->setInputEncoding('UTF-8');
        }
        $reader->setReadDataOnly(true);
        $info = $reader->listWorksheetInfo($file->getPathname());
        if (($info[0]['totalRows'] ?? 0) > 20100 || ($info[0]['totalColumns'] ?? 0) > 100) {
            throw ValidationException::withMessages(['file' => trans_message('estimate_operations.too_large')]);
        }
        if ($type === 'Xlsx') {
            $reader->setLoadSheetsOnly($info[0]['worksheetName']);
        }
        $reader->setReadFilter(new class ($rowLimit, $columns) implements IReadFilter {
            public function __construct(private readonly int $rowLimit, private readonly ?array $columns)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->rowLimit && ($this->columns === null || in_array(Coordinate::columnIndexFromString($columnAddress) - 1, $this->columns, true));
            }
        });
        $spreadsheet = $reader->load($file->getPathname());
        try {
            $sheet = $spreadsheet->getSheet(0);

            return ['values' => $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().min($rowLimit, $sheet->getHighestDataRow()), null, false, false, false), 'row_count' => $info[0]['totalRows']];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['file' => trans_message('estimate_operations.price_file_invalid')]);
    }
}
