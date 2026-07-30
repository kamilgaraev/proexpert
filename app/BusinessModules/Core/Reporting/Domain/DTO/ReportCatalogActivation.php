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
        foreach ([$publishedCodes, $bindingCodes, $publicationLockHashes, $conformanceHashes] as $items) {
            if (! array_is_list($items) || count($items) !== 28 || count(array_unique($items, SORT_REGULAR)) !== 28) {
                throw new InvalidArgumentException('report_catalog_activation_invalid');
            }
        }
        if ($status !== 'catalog_activated' || preg_match('/^[a-f0-9]{40}$/', $releaseSha) !== 1) {
            throw new InvalidArgumentException('report_catalog_activation_invalid');
        }
    }
}
