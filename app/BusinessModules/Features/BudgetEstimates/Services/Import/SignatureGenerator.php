<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

class SignatureGenerator
{
    /**
     * Generate MD5 hash from normalized headers.
     */
    public function generate(array $headers): string
    {
        // 1. Normalize headers: lowercase, trim, remove non-alphanumeric
        $normalized = array_map(function ($h) {
            $h = mb_strtolower(trim((string)$h));
            return preg_replace('/[^a-zа-я0-9]/u', '', $h);
        }, $headers);

        // 2. Remove empty headers
        $normalized = array_filter($normalized);

        // 3. Sort to ensure column order doesn't affect signature (optional, but usually we want it to be order-independent)
        sort($normalized);

        // 4. Join and hash
        return md5(implode('|', $normalized));
    }
}
