<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Models\User;
use App\Services\Mobile\MobileMutationIdempotency;
use DomainException;

final readonly class DesignProjectIssueSubmissionService
{
    public function __construct(
        private DesignProjectIssueService $issues,
        private DesignIssueContextResolver $contexts,
        private DesignAccessService $access,
        private MobileMutationIdempotency $idempotency,
    ) {}

    public function submit(User $actor, int $organizationId, int $projectId, array $payload, ?string $key): QualityDefect
    {
        if ($key === null || $key === '') {
            return $this->issues->create($actor, $organizationId, $projectId, $payload);
        }
        if (! $this->access->canAccessProject($actor, $organizationId, $projectId, 'design-management.review')) {
            throw new DomainException(trans_message('errors.forbidden'));
        }
        $this->contexts->resolve($actor, $organizationId, $projectId, $payload);
        $result = $this->idempotency->run(
            $organizationId, (int) $actor->id, $key, 'design-management.admin.issue.create',
            ['project_id' => $projectId, ...$payload],
            fn (): QualityDefect => $this->issues->create($actor, $organizationId, $projectId, $payload),
            fn (int $id): ?QualityDefect => $this->issues->find($actor, $organizationId, $id),
        );
        if (! $result instanceof QualityDefect || (int) $result->project_id !== $projectId) {
            throw new DomainException(trans_message('design_issues.errors.target_not_found'));
        }

        return $result;
    }
}
