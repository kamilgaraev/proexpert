<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use InvalidArgumentException;

final readonly class ReportAuthorizationGrant
{
    public function __construct(
        public string $definitionHash,
        public ReportOperation $operation,
        public ?string $exportFormat,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $definitionHash) !== 1) {
            throw new InvalidArgumentException('report_authorization_grant_invalid');
        }
    }

    public function matches(
        ReportDefinition $definition,
        ReportOperation $operation,
        ?string $exportFormat,
    ): bool {
        $operationMatches = $this->operation === $operation
            || ($this->operation === ReportOperation::VIEW
                && in_array($operation, [
                    ReportOperation::VIEW_SENSITIVE,
                    ReportOperation::VIEW_AUDIT,
                ], true));

        return $operationMatches
            && hash_equals($this->definitionHash, $definition->definitionHash->value)
            && $this->exportFormat === $exportFormat;
    }
}
