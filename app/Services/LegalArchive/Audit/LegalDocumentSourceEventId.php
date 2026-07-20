<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Audit;

final class LegalDocumentSourceEventId
{
    public const MAX_LENGTH = 191;

    public static function canonical(string $prefix, ?string $material = null): string
    {
        if ($material === null && preg_match('/^[a-z0-9:_-]+:[a-f0-9]{64}$/D', $prefix) === 1) {
            return $prefix;
        }
        $material ??= $prefix;
        $safePrefix = strtolower($prefix);
        $safePrefix = (string) preg_replace('/[^a-z0-9:_-]+/', '-', $safePrefix);
        $safePrefix = trim($safePrefix, ':-_');
        $safePrefix = $safePrefix === '' ? 'event' : $safePrefix;
        $hash = hash('sha256', $material);
        $prefixLength = self::MAX_LENGTH - strlen($hash) - 1;

        return substr($safePrefix, 0, $prefixLength).':'.$hash;
    }
}
