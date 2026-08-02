<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BindProjectReportScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $filters = $request->input('filters');
        if (! is_array($filters)) {
            return $next($request);
        }

        if (array_key_exists('organization_id', $filters) || array_key_exists('project_id', $filters)) {
            return AdminResponse::error(
                trans_message('reports.errors.report_request_invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $project = $request->attributes->get('project');
        $organization = $request->attributes->get('current_organization');
        if ($project === null || $organization === null) {
            return AdminResponse::error(
                trans_message('reports.errors.report_request_invalid'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
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
}
