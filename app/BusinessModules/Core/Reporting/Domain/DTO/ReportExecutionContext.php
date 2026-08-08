<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use InvalidArgumentException;

final readonly class ReportExecutionContext
{
    public function __construct(
        public ReportActor $actor,
        public ReportScope $scope,
        public ReportVisibility $visibility,
        public AuthorizationDecisionContext $authorization,
        public ?ReportAuthorizationGrant $grant = null,
    ) {
        if ($actor->status !== 'active') {
            throw new InvalidArgumentException('execution_context_actor_invalid');
        }

        if ($scope->organizationId !== $authorization->organizationId || $scope->timezone->getName() !== $authorization->timezone->getName()) {
            throw new InvalidArgumentException('execution_context_scope_mismatch');
        }
    }

    public function correlationId(): string
    {
        return $this->authorization->correlationId;
    }
}
