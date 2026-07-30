<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCatalogGroup;
use InvalidArgumentException;

final readonly class ReportCatalogMetadata
{
    public function __construct(
        public string $code,
        public string $titleKey,
        public ReportCatalogGroup $catalogGroup,
        public string $category,
        public string $grain,
        public int $wave,
        public int $manifestOrdinal,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || $titleKey !== 'reports.catalog.'.$code
            || trim($category) === ''
            || trim($grain) === ''
            || ! in_array($wave, [1, 2, 3], true)
            || $manifestOrdinal < 0
            || $manifestOrdinal > 27) {
            throw new InvalidArgumentException('report_catalog_metadata_invalid');
        }
    }
}
