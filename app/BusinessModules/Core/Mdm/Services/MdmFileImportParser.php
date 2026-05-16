<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use SplFileObject;

class MdmFileImportParser
{
    public function parse(string $path, array $mapping = []): array
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $rows = in_array($extension, ['xlsx', 'xls'], true)
            ? $this->parseSpreadsheet($path)
            : $this->parseCsv($path);

        if ($rows === []) {
            return [];
        }

        $headers = array_map(static fn ($value): string => trim((string) $value), array_shift($rows));
        $result = [];

        foreach ($rows as $row) {
            $assoc = [];
            foreach ($headers as $index => $header) {
                $target = $mapping[$header] ?? $header;
                if ($target === '') {
                    continue;
                }
                $assoc[$target] = $row[$index] ?? null;
            }
            $result[] = $assoc;
        }

        return $result;
    }

    private function parseCsv(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl(';');
        $rows = [];

        foreach ($file as $row) {
            if (!is_array($row) || $row === [null]) {
                continue;
            }
            $row[0] = isset($row[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) : $row[0];
            $rows[] = $row;
        }

        return $rows;
    }

    private function parseSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();

        return $sheet->toArray(null, true, true, false);
    }
}
