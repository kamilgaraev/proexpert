<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

final readonly class ReportDefinitionSemanticDiff
{
    public function __construct(
        public bool $formulaChanged,
        public bool $sourceSchemaChanged,
        public bool $contractChanged,
        public bool $rendererChanged,
        public bool $permissionsChanged,
        public bool $readinessChanged,
    ) {}
}
