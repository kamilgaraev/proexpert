<?php

declare(strict_types=1);

namespace App\Services\Import;

use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

final class BoundedSpreadsheetReader
{
    public const MAX_ROWS = 10_000;
    public const MAX_COLUMNS = 128;
    public const MAX_CELLS = 200_000;
    public const MAX_TEXT_BYTES = 8_000_000;
    public const MAX_OUTPUT_BYTES = 16_000_000;

    public function read(string $path, string $extension, string $profile): array
    {
        $extension = strtolower($extension);
        $sizeLimit = match ($profile) {
            'catalog' => 10 * 1024 * 1024,
            'materials' => 2 * 1024 * 1024,
            default => 0,
        };
        $size = is_file($path) && is_readable($path) ? filesize($path) : false;
        if ($sizeLimit === 0 || $size === false || $size > $sizeLimit
            || !in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            throw new SpreadsheetImportException('spreadsheet_import_rejected');
        }

        $php = (new PhpExecutableFinder)->find(false);
        if ($php === false) {
            throw new SpreadsheetImportException('spreadsheet_import_rejected');
        }
        $process = new Process([
            $php, '-d', 'memory_limit=256M', '-d', 'max_execution_time=15',
            __DIR__.'/parse-spreadsheet.php', $path, $extension, $profile,
        ]);
        $process->setTimeout(20);
        $outputBytes = 0;

        try {
            $process->run(function (string $type, string $buffer) use (&$outputBytes, $process): void {
                $outputBytes += strlen($buffer);
                if ($outputBytes > self::MAX_OUTPUT_BYTES) {
                    $process->stop(0);
                    throw new SpreadsheetImportException('spreadsheet_import_rejected');
                }
            });
            if (!$process->isSuccessful()) {
                throw new SpreadsheetImportException('spreadsheet_import_rejected');
            }
            $rows = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($rows)) {
                throw new SpreadsheetImportException('spreadsheet_import_rejected');
            }
            self::validateRows($rows);

            return $rows;
        } catch (SpreadsheetImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new SpreadsheetImportException('spreadsheet_import_rejected', 0, $exception);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
    }

    public static function validateRows(array $rows): void
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new SpreadsheetImportException('spreadsheet_import_rejected');
        }
        $cells = 0;
        $textBytes = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) > self::MAX_COLUMNS) {
                throw new SpreadsheetImportException('spreadsheet_import_rejected');
            }
            $cells += count($row);
            if ($cells > self::MAX_CELLS) {
                throw new SpreadsheetImportException('spreadsheet_import_rejected');
            }
            foreach ($row as $value) {
                if (!is_scalar($value) && $value !== null) {
                    throw new SpreadsheetImportException('spreadsheet_import_rejected');
                }
                $length = is_string($value) ? strlen($value) : 0;
                $textBytes += $length;
                if ($length > 131_068 || $textBytes > self::MAX_TEXT_BYTES) {
                    throw new SpreadsheetImportException('spreadsheet_import_rejected');
                }
            }
        }
    }
}
