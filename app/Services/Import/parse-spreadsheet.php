<?php

declare(strict_types=1);

use App\Services\Import\BoundedSpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 3).'/vendor/autoload.php';

$spreadsheet = null;
try {
    $path = $argv[1] ?? '';
    $extension = $argv[2] ?? '';
    $profile = $argv[3] ?? '';
    $limit = match ($profile) {
        'catalog' => 10 * 1024 * 1024,
        'materials' => 2 * 1024 * 1024,
        default => 0,
    };
    if ($limit === 0 || !is_file($path) || filesize($path) > $limit
        || !in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
        exit(2);
    }

    $zip = new ZipArchive;
    if ($zip->open($path) === true) {
        try {
            if ($zip->numFiles > 2048) {
                exit(2);
            }
            $total = 0;
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if ($entry === false || $entry['size'] > 5_000_000) {
                    exit(2);
                }
                $name = $entry['name'];
                $total += $entry['size'];
                if ($total > 20_000_000 || isset($names[$name])
                    || str_contains($name, '\\') || str_contains($name, "\0")
                    || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name)) {
                    exit(2);
                }
                $names[$name] = true;
            }
        } finally {
            $zip->close();
        }
    }

    if ($profile === 'materials' && $extension === 'csv') {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            exit(2);
        }
        $rows = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $rows[] = str_getcsv($line, escape: '\\');
                if (count($rows) > BoundedSpreadsheetReader::MAX_ROWS) {
                    exit(2);
                }
            }
        } finally {
            fclose($handle);
        }
    } else {
        $reader = IOFactory::createReaderForFile($path, ['Xlsx', 'Xls', 'Csv']);
        $spreadsheet = $reader->load($path);
        $spreadsheet->setDomainWhiteList([]);
        if ($spreadsheet->getSheetCount() > 32) {
            exit(2);
        }
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $sheet->garbageCollect();
            $rowCount = $sheet->getHighestRow();
            $columnCount = Coordinate::columnIndexFromString($sheet->getHighestColumn());
            if ($rowCount > BoundedSpreadsheetReader::MAX_ROWS
                || $columnCount > BoundedSpreadsheetReader::MAX_COLUMNS
                || $rowCount * $columnCount > BoundedSpreadsheetReader::MAX_CELLS) {
                exit(2);
            }
        }
        $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, $profile === 'materials');
    }

    BoundedSpreadsheetReader::validateRows($rows);
    $output = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (strlen($output) > BoundedSpreadsheetReader::MAX_OUTPUT_BYTES) {
        exit(2);
    }
    echo $output;
} catch (Throwable) {
    exit(2);
} finally {
    $spreadsheet?->disconnectWorksheets();
}
