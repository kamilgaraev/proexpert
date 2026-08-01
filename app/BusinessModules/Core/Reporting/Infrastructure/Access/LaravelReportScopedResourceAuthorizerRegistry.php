<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportScopedResourceAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\Models\User;
use DateTimeImmutable;
use Throwable;

final class LaravelReportScopedResourceAuthorizerRegistry
{
    private array $handlers = [];

    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            if (! $handler instanceof ReportScopedResourceAuthorizer) {
                throw new \InvalidArgumentException('report_resource_authorizer_invalid');
            }
            $kind = $handler->kind();
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $kind) !== 1
                || in_array($kind, ['all', 'generic'], true)
                || isset($this->handlers[$kind])) {
                throw new \InvalidArgumentException('report_resource_authorizer_invalid');
            }
            $this->handlers[$kind] = $handler;
        }
    }

    public function authorizeAll(User $actor, int $organizationId, array $resources, DateTimeImmutable $occurredAt): void
    {
        try {
            foreach ($resources as $resource) {
                if (! $resource instanceof ReportScopedResource || ! isset($this->handlers[$resource->kind])) {
                    throw new \InvalidArgumentException('report_resource_authorizer_missing');
                }
                $facts = new CurrentReportAuthorizationFacts(
                    'queue',
                    (int) $actor->getAuthIdentifier(),
                    $organizationId,
                    $resource->projectId,
                    $resource,
                    $occurredAt,
                );
                $decision = $this->handlers[$resource->kind]->authorize($actor, $organizationId, $resource, $facts);
                if (! $decision->granted
                    || $decision->actorId !== (int) $actor->getAuthIdentifier()
                    || $decision->organizationId !== $organizationId
                    || $decision->projectId !== $resource->projectId
                    || ! hash_equals($decision->kind, $resource->kind)
                    || $decision->id !== $resource->id) {
                    throw new \InvalidArgumentException('report_resource_authorization_mismatch');
                }
            }
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, previous: $exception);
        }
    }
}
