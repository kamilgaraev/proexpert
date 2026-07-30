<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class LoadedReportManifest
{
    public array $definitions;

    public function __construct(
        public string $catalog,
        public string $contractVersion,
        public Sha256Hash $bytesHash,
        array $definitions,
    ) {
        $expectedCount = match ($catalog) {
            'management-catalog.v1' => 28,
            'official-document-catalog.v1' => 1,
            default => throw new InvalidArgumentException('report_manifest_catalog_invalid'),
        };

        if ($contractVersion !== '1.0.0'
            || ! array_is_list($definitions)
            || count($definitions) !== $expectedCount) {
            throw new InvalidArgumentException('report_manifest_definition_count_invalid');
        }

        $codes = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition)
                || array_is_list($definition)
                || ! isset($definition['code'])
                || ! is_string($definition['code'])
                || isset($codes[$definition['code']])) {
                throw new InvalidArgumentException(
                    isset($definition['code']) && is_string($definition['code']) && isset($codes[$definition['code']])
                        ? 'report_manifest_code_duplicate'
                        : 'report_manifest_definition_invalid',
                );
            }

            $codes[$definition['code']] = true;
        }

        $this->definitions = $definitions;
    }
}
