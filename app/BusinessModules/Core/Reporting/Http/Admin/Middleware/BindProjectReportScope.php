<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BindProjectReportScope
{
    private const FORBIDDEN_CONTEXT_FIELDS = [
        'organization_id',
        'organization_ids',
        'project_id',
        'project_ids',
        'user_id',
        'owner_id',
        'role',
        'permission',
        'scope',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasClientContextOverride($request)) {
            return $this->invalidRequest();
        }

        $project = $request->attributes->get('project');
        $organization = $request->attributes->get('current_organization');
        if ($project === null || $organization === null) {
            return $this->invalidRequest();
        }

        $request->attributes->set('report_project_scope_id', (int) $project->id);

        $filters = $request->input('filters');
        if ($request->isMethod('GET') || ! is_array($filters)) {
            return $next($request);
        }

        $request->merge([
            'filters' => [
                ...$filters,
                'organization_id' => (string) $organization->id,
                'project_id' => (string) $project->id,
            ],
        ]);

        return $next($request);
    }

    private function hasClientContextOverride(Request $request): bool
    {
        foreach (self::FORBIDDEN_CONTEXT_FIELDS as $field) {
            if ($request->exists($field)) {
                return true;
            }
        }

        $filters = $request->input('filters');
        if (! is_array($filters)) {
            return false;
        }

        return array_intersect(self::FORBIDDEN_CONTEXT_FIELDS, array_keys($filters)) !== [];
    }

    private function invalidRequest(): Response
    {
        return AdminResponse::error(
            trans_message('reports.errors.report_request_invalid'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
