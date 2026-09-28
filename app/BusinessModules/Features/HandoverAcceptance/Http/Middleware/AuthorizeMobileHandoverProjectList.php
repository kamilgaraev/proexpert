<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Middleware;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileProjectAccessResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizeMobileHandoverProjectList
{
    public const PROJECT_IDS_ATTRIBUTE = 'handover_authorized_project_ids';

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly MobileProjectAccessResolver $projectAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if (! $user instanceof User || $organizationId <= 0) {
            return MobileResponse::error(trans_message('errors.unauthorized'), 403);
        }

        $requestedProjectId = $request->input('project_id');
        if ($requestedProjectId !== null && $requestedProjectId !== '') {
            $projectId = filter_var($requestedProjectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($projectId === false) {
                return MobileResponse::error(trans_message('errors.unauthorized'), 403);
            }

            try {
                $this->projectAccess->resolve($user, $organizationId, $projectId, trans_message('errors.unauthorized'));
            } catch (\DomainException) {
                return MobileResponse::error(trans_message('errors.unauthorized'), 403);
            }

            $projectIds = $this->allowsProjectView($user, $organizationId, $projectId)
                ? [$projectId]
                : [];
        } else {
            $projectIds = array_values(array_filter(
                $this->projectAccess->ids($user, $organizationId),
                fn (int $projectId): bool => $this->allowsProjectView($user, $organizationId, $projectId),
            ));
        }

        if ($projectIds === []) {
            return MobileResponse::error(trans_message('errors.unauthorized'), 403);
        }

        $request->attributes->set(self::PROJECT_IDS_ATTRIBUTE, $projectIds);

        return $next($request);
    }

    private function allowsProjectView(User $user, int $organizationId, int $projectId): bool
    {
        return $this->authorization->can($user, 'handover-acceptance.view', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ]);
    }
}
