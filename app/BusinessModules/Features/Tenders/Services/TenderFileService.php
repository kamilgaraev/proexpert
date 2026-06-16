<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Services;

use Illuminate\Support\Str;

final class TenderFileService
{
    public function buildStoredPath(int $organizationId, string $tenderId, string $category, string $originalName): string
    {
        $safeName = trim((string) Str::of($originalName)->replaceMatches('/[^A-Za-z0-9А-Яа-я._-]+/u', '-'), '-');

        return sprintf(
            'org-%d/tenders/%s/%s/%s-%s',
            $organizationId,
            $tenderId,
            $category,
            (string) Str::uuid(),
            $safeName !== '' ? $safeName : 'file'
        );
    }
}
