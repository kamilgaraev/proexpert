<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportAuthorizationSubject
{
    public function __construct(
        public ReportDispatchAggregate $aggregateKind,
        public string $aggregateId,
        public ReportDefinition $definition,
        public ReportScope $scope,
        public ?ReportSnapshotRef $snapshot,
        public ?string $parentRunId,
        public ?Sha256Hash $artifactIdentityHash,
    ) {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $aggregateId) !== 1) {
            throw new InvalidArgumentException('report_authorization_subject_invalid');
        }

        if ($aggregateKind === ReportDispatchAggregate::RUN) {
            if ($parentRunId !== null || $artifactIdentityHash !== null) {
                throw new InvalidArgumentException('report_authorization_subject_invalid');
            }
        } elseif (
            $snapshot === null
            || $parentRunId === null
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $parentRunId) !== 1
        ) {
            throw new InvalidArgumentException('report_authorization_subject_invalid');
        }

        if ($snapshot !== null && (
            $snapshot->scope->canonicalIdentity() !== $scope->canonicalIdentity()
            || $snapshot->definitionHash->value !== $definition->definitionHash->value
            || $snapshot->formulaVersion !== $definition->formulaVersion
        )) {
            throw new InvalidArgumentException('report_authorization_subject_invalid');
        }
    }
}
