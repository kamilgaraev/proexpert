<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BindOrganizationReportScope
{
    private const FORBIDDEN_CONTEXT_FIELDS = [
        'organization_id', 'organization_ids', 'project_id', 'user_id', 'owner_id',
        'role', 'permission', 'scope',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->hasClientContextOverride($request)) {
            return $this->invalidRequest();
        }
        $filters = $request->input('filters');
        if ($request->isMethod('GET') || ! is_array($filters)) {
            return $next($request);
        }
        $organization = $request->attributes->get('current_organization');
        if ($organization === null) {
            return $this->invalidRequest();
        }
        $request->merge(['filters' => [...$filters, 'organization_id' => (string) $organization->id]]);

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

        return is_array($filters)
            && array_intersect(self::FORBIDDEN_CONTEXT_FIELDS, array_keys($filters)) !== [];
    }

    private function invalidRequest(): Response
    {
        return AdminResponse::error(
            trans_message('reports.errors.report_request_invalid'),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }
}
