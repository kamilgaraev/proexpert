<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use Closure;

class PdfParserRuntime
{
    /**
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function withRaisedMemoryLimit(Closure $callback): mixed
    {
        $previousLimit = ini_get('memory_limit');
        $targetLimit = (string) config('estimate-generation.ocr.pdf_parser_memory_limit', '512M');
        $changed = false;

        if ($this->shouldRaiseMemoryLimit($previousLimit, $targetLimit)) {
            $changed = @ini_set('memory_limit', $targetLimit) !== false;
        }

        try {
            return $callback();
        } finally {
            if ($changed && $previousLimit !== false) {
                @ini_set('memory_limit', $previousLimit);
            }
        }
    }

    private function shouldRaiseMemoryLimit(string|false $currentLimit, string $targetLimit): bool
    {
        $targetBytes = $this->memoryLimitToBytes($targetLimit);

        if ($targetBytes === null) {
            return false;
        }

        if ($currentLimit === false) {
            return true;
        }

        $currentBytes = $this->memoryLimitToBytes($currentLimit);

        if ($currentBytes === null || $currentBytes === PHP_INT_MAX) {
            return false;
        }

        return $targetBytes > $currentBytes;
    }

    private function memoryLimitToBytes(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if ($value === '-1') {
            return PHP_INT_MAX;
        }

        if (!preg_match('/^([0-9]+)\s*([KMG])?$/i', $value, $matches)) {
            return null;
        }

        $bytes = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $bytes * 1024 * 1024 * 1024,
            'M' => $bytes * 1024 * 1024,
            'K' => $bytes * 1024,
            default => $bytes,
        };
    }
}
