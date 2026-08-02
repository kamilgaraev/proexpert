<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Controllers;

use App\BusinessModules\Features\SafetyManagement\Http\Requests\AssignWorkforceSitesRequest;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\SafetySiteAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;

final class SafetyWorkforceSiteAssignmentController extends Controller
{
    public function __construct(private readonly SafetySiteAssignmentService $service) {}

    public function store(AssignWorkforceSitesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $mappings = $this->service->assignMany(
                (int) $request->attributes->get('current_organization_id'),
                (int) $validated['project_id'],
                $validated['safety_site_ids'],
                (int) $validated['workforce_assignment_id'],
                (int) $validated['employee_id'],
                (string) $validated['valid_from'],
                isset($validated['valid_to']) ? (string) $validated['valid_to'] : null,
            );

            return AdminResponse::success([
                'assignments' => array_map(static fn ($mapping): array => [
                    'id' => (int) $mapping->id,
                    'project_id' => (int) $mapping->project_id,
                    'safety_site_id' => (int) $mapping->safety_site_id,
                    'workforce_assignment_id' => (int) $mapping->workforce_assignment_id,
                    'employee_id' => (int) $mapping->employee_id,
                    'valid_from' => $mapping->valid_from->toDateString(),
                    'valid_to' => $mapping->valid_to?->toDateString(),
                ], $mappings),
            ], null, 201);
        } catch (DomainException) {
            return AdminResponse::error(trans_message('reports.errors.report_source_unavailable'), 422);
        }
    }
}
