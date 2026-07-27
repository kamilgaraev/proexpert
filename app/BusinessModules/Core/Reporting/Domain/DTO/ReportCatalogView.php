<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportCatalogView
{
    public function __construct(
        public string $contractVersion,
        public Sha256Hash $manifestSha256,
        public array $definitions,
    ) {
        if ($contractVersion !== '1.0.0' || $definitions === [] || !array_is_list($definitions)) {
            throw new InvalidArgumentException('report_catalog_view_invalid');
        }

        $codes = [];
        $hashes = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ReportDefinition || isset($codes[$definition->code]) || isset($hashes[$definition->definitionHash->value])) {
                throw new InvalidArgumentException('report_catalog_view_invalid');
            }
            $codes[$definition->code] = true;
            $hashes[$definition->definitionHash->value] = true;
        }
    }
}
