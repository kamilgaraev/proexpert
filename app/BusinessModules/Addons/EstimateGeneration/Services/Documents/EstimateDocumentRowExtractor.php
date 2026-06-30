<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

final class EstimateDocumentRowExtractor
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractFromText(string $text): array
    {
        $rows = [];

        foreach ($this->lines($text) as $line) {
            $row = $this->extractFromLine($line);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extractFromLine(string $line): ?array
    {
        $referenceCode = $this->referenceCodeFromLine($line);

        if ($referenceCode === null) {
            return null;
        }

        $quantity = $this->quantityFromLine($line);

        return [
            'line' => $line,
            'code_kind' => $referenceCode['kind'],
            'code_prefix' => $referenceCode['prefix'],
            'code' => $referenceCode['code'],
            'raw_code' => $referenceCode['raw_code'],
            'name' => $this->nameFromLine($line, $referenceCode['raw_code'], $referenceCode['code']),
            'unit' => $quantity['unit'],
            'quantity' => $quantity['value'],
            'quantity_source' => $quantity['source'],
            'quantity_basis' => $quantity['basis'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), preg_split('/\r\n|\r|\n/u', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * @return array{kind: string, code: string, prefix: string|null, raw_code: string}|null
     */
    private function referenceCodeFromLine(string $line): ?array
    {
        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:(?<prefix>ГЭСН|ФЕР|ТЕР)\s*)?(?<code>\d{2}-\d{2}-\d{3}-\d{2,3}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'work_norm',
                'code' => (string) $match['code'],
                'prefix' => $this->nullableString($match['prefix'] ?? null),
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:(?<prefix>ФСБЦ|КСР)\s*)?(?<code>\d{2}\.\d\.\d{2}\.\d{2}-\d{4}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'fsbc_resource',
                'code' => (string) $match['code'],
                'prefix' => $this->nullableString($match['prefix'] ?? null),
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:(?<prefix>ФСБЦ|КСР)\s*)?(?<code>\d{2}\.\d{2}\.\d{2}-\d{3}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'fsbc_machine_resource',
                'code' => (string) $match['code'],
                'prefix' => $this->nullableString($match['prefix'] ?? null),
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:(?<prefix>ФСБЦ|КСР)\s*)?(?<code>\d{1,2}-\d{3}-\d{2,3}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'ksr_resource',
                'code' => (string) $match['code'],
                'prefix' => $this->nullableString($match['prefix'] ?? null),
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        return null;
    }

    /**
     * @return array{value: float, unit: string, basis: string, source: string}
     */
    private function quantityFromLine(string $line): array
    {
        if (preg_match('/(\d+(?:[,.]\d+)?)\s*(100\s*)?(м2|м²|м3|м³|м|шт\.?|т|кг|компл\.?)/ui', $line, $match) === 1) {
            $multiplier = trim((string) ($match[2] ?? '')) === '100' ? 100.0 : 1.0;

            return [
                'value' => round((float) str_replace(',', '.', $match[1]) * $multiplier, 4),
                'unit' => $this->normalizeUnit((string) $match[3]),
                'basis' => 'Количество найдено в проектной документации рядом с явной ссылкой на норму.',
                'source' => 'project_document',
            ];
        }

        return [
            'value' => 1.0,
            'unit' => 'компл',
            'basis' => 'В проектной документации найдена ссылка на норму без надежного количества.',
            'source' => 'review',
        ];
    }

    private function nameFromLine(string $line, string $rawCode, string $code): string
    {
        $name = str_replace($rawCode, '', $line);
        $name = str_replace($code, '', $name);
        $name = preg_replace('/(?:^|[^\d])\d+(?:[,.]\d+)?\s*(?:100\s*)?(?:м2|м²|м3|м³|м|шт\.?|т|кг|компл\.?)(?:$|[^\p{L}\p{N}])/ui', ' ', $name) ?? $name;
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name) ?? $name);
        $name = trim($name, " \t\n\r\0\x0B:-—–.,;");

        return $name !== '' ? mb_substr($name, 0, 180) : 'Работа по норме ' . $code;
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit, ". \t\n\r\0\x0B"));

        return match ($unit) {
            'м²' => 'м2',
            'м³' => 'м3',
            'шт' => 'шт',
            'компл' => 'компл',
            default => $unit,
        };
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
