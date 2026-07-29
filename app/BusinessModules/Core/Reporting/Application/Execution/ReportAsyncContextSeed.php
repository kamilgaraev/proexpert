<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Execution;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use InvalidArgumentException;

final readonly class ReportAsyncContextSeed
{
    public function __construct(
        public string $aggregateKind,
        public string $aggregateId,
        public int $organizationId,
        public int $requesterActorId,
        public ReportScope $requestedScope,
        public ReportDefinition $definition,
        public ?string $correlationLineageId,
    ) {
        if (
            $aggregateKind !== 'run'
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/', $aggregateId) !== 1
            || $organizationId < 1
            || $requesterActorId < 1
            || $requestedScope->organizationId !== $organizationId
            || $definition->publicationReadiness->value !== 'published'
            || ($correlationLineageId !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $correlationLineageId) !== 1)
        ) {
            throw new InvalidArgumentException('report_async_context_seed_invalid');
        }
    }
}
