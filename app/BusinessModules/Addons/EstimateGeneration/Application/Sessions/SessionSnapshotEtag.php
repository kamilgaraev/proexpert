<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

final class SessionSnapshotEtag
{
    private const REPRESENTATION_VERSION = 'v2';

    public static function forRevision(int $organizationId, int $sessionId, string $revision): string
    {
        return sprintf(
            '"eg-snapshot-%s-%d-%d-%s"',
            self::REPRESENTATION_VERSION,
            $organizationId,
            $sessionId,
            hash('sha256', self::REPRESENTATION_VERSION."\0".$organizationId."\0".$sessionId."\0".$revision),
        );
    }

    public static function matches(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        $header = trim($header);
        if ($header === '*') {
            return true;
        }
        if (str_contains($header, '*')) {
            return false;
        }

        $expected = self::opaqueTag($etag);
        if ($expected === null) {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            if (self::opaqueTag(trim($candidate)) === $expected) {
                return true;
            }
        }

        return false;
    }

    private static function opaqueTag(string $value): ?string
    {
        if (str_starts_with($value, 'W/')) {
            $value = substr($value, 2);
        }

        if (strlen($value) < 2 || $value[0] !== '"' || $value[strlen($value) - 1] !== '"') {
            return null;
        }

        $opaque = substr($value, 1, -1);

        return $opaque !== '' && ! str_contains($opaque, '"') ? $opaque : null;
    }
}
