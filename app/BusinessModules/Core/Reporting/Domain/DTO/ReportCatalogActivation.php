<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ReportCatalogActivation
{
    public function __construct(
        public string $status,
        public string $releaseSha,
        public Sha256Hash $previousManifestHash,
        public Sha256Hash $publishedManifestHash,
        public array $publishedCodes,
        public array $bindingCodes,
        public array $publicationLockHashes,
        public array $conformanceHashes,
        public DateTimeImmutable $activatedAt,
    ) {
        if (! self::isCodeList($publishedCodes)
            || ! self::isCodeList($bindingCodes)
            || ! self::isHashList($publicationLockHashes)
            || ! self::isHashList($conformanceHashes)
            || ! self::sameSet($publishedCodes, $bindingCodes)) {
            throw new InvalidArgumentException('report_catalog_activation_invalid');
        }
        if ($status !== 'catalog_activated'
            || preg_match('/^[a-f0-9]{40}$/D', $releaseSha) !== 1
            || $activatedAt->getTimezone()->getName() !== 'UTC'
            || $activatedAt->format('u') !== '000000') {
            throw new InvalidArgumentException('report_catalog_activation_invalid');
        }
    }

    private static function isCodeList(array $items): bool
    {
        if (! array_is_list($items) || count($items) !== 28 || count(array_unique($items, SORT_STRING)) !== 28) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_string($item) || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $item) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function isHashList(array $items): bool
    {
        if (! array_is_list($items) || count($items) !== 28 || count(array_unique($items, SORT_STRING)) !== 28) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_string($item) || preg_match('/^[a-f0-9]{64}$/D', $item) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function sameSet(array $left, array $right): bool
    {
        sort($left, SORT_STRING);
        sort($right, SORT_STRING);

        return $left === $right;
    }
}
