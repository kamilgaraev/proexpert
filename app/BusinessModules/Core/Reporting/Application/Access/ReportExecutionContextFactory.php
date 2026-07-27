<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\Services\Logging\Context\RequestContext;
use DateTimeZone;
use Illuminate\Http\Request;
use Throwable;

final readonly class ReportExecutionContextFactory
{
    public function __construct(
        private ReportActorLoader $actorLoader,
        private OrganizationReportScopeResolver $scopeResolver,
        private RequestContext $requestContext,
    ) {
    }

    public function create(
        int $actorId,
        AuthorizationDecisionContext $authorization,
    ): ReportExecutionContext {
        try {
            if ($actorId < 1) {
                throw new \InvalidArgumentException('report_actor_invalid');
            }

            $actor = $this->actorLoader->loadActive($actorId);
            if ($actor->id !== $actorId) {
                throw new \InvalidArgumentException('report_actor_identity_mismatch');
            }

            $scope = $this->scopeResolver->resolve($actor, $authorization);
            $permissions = array_fill_keys($actor->permissionSlugs, true);
            $canView = isset($permissions['reports.view']);
            $canExport = $canView && isset($permissions['reports.export']);

            return new ReportExecutionContext(
                $actor,
                $scope,
                new ReportVisibility(
                    $canView,
                    $canView && isset($permissions['reports.run']),
                    $canExport,
                    $canExport && isset($permissions['reports.download']),
                    $canView && isset($permissions['reports.manage']),
                    $canView && isset($permissions['reports.sensitive']),
                    $canView && isset($permissions['reports.audit']),
                ),
                $authorization,
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }
    }

    public function fromHttp(Request $request): ReportExecutionContext
    {
        try {
            $authenticated = $request->user('api_admin');
            if (!is_object($authenticated) || !method_exists($authenticated, 'getAuthIdentifier')) {
                throw new \InvalidArgumentException('report_actor_missing');
            }

            $actorId = (int) $authenticated->getAuthIdentifier();
            $organizationId = (int) $request->attributes->get('current_organization_id');
            if ($actorId < 1 || $organizationId < 1) {
                throw new \InvalidArgumentException('report_scope_missing');
            }

            $route = $request->route();
            $routeName = is_object($route) && method_exists($route, 'getName')
                ? $route->getName()
                : null;

            $authorization = new AuthorizationDecisionContext(
                channel: 'http',
                organizationId: $organizationId,
                holdingOrganizationIds: (array) $request->attributes->get(
                    'holding_organization_ids',
                    [$organizationId],
                ),
                projectIds: (array) $request->attributes->get('allowed_project_ids', []),
                resourceIds: (array) $request->attributes->get('allowed_resource_ids', []),
                timezone: new DateTimeZone((string) $request->attributes->get('organization_timezone', 'UTC')),
                correlationId: $this->requestContext->getCorrelationId(),
                transportMetadata: ['route' => is_string($routeName) ? $routeName : null],
            );

            return $this->create($actorId, $authorization);
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }
    }
}
