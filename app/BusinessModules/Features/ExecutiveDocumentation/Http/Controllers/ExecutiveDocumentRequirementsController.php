<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Controllers;

use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\AttachExecutiveDocumentRequirementEvidenceRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\ListExecutiveDocumentRequirementsRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\MarkExecutiveDocumentRequirementNotApplicableRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\StoreExecutiveDocumentRequirementRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Http\Requests\UpdateExecutiveDocumentRequirementConditionsRequest;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRequirement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsQueryService;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentRequirementsService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final class ExecutiveDocumentRequirementsController extends \App\Http\Controllers\Controller
{
    public function callAction($method, $parameters): JsonResponse
    {
        try {
            return parent::callAction($method, $parameters);
        } catch (\App\Exceptions\BusinessLogicException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409], true) ? $exception->getCode() : 409;
            $key = match ($status) {
                403 => 'executive_documentation.errors.forbidden',
                404 => 'executive_documentation.errors.document_not_found',
                default => 'executive_documentation.requirements.composition_conflict',
            };

            return AdminResponse::error(trans_message($key), $status);
        }
    }

    public function __construct(private readonly ExecutiveDocumentRequirementsService $service, private readonly ExecutiveDocumentRequirementsQueryService $query) {}

    public function index(ListExecutiveDocumentRequirementsRequest $request, ExecutiveDocumentSet $set): JsonResponse
    {
        return $this->listResponse($this->query->active($set, $request->user(), $request->validated()));
    }

    public function history(ListExecutiveDocumentRequirementsRequest $request, ExecutiveDocumentSet $set): JsonResponse
    {
        return $this->listResponse($this->query->history($set, $request->user(), $request->validated()));
    }

    private function listResponse(array $result): JsonResponse
    {
        $paginator = $result['paginator'];

        return AdminResponse::paginated($paginator->items(), [
            'current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(), 'composition_revision' => $result['composition_revision'],
        ], summary: $result['summary']);
    }

    public function replace(StoreExecutiveDocumentRequirementRequest $request, ExecutiveDocumentSet $set): JsonResponse
    {
        $this->service->replaceForSet(
            $set,
            $request->validated('requirements'),
            $request->user(),
            app(AuthorizationService::class),
            $request->validated('expected_composition_revision') === null ? null : (int) $request->validated('expected_composition_revision'),
            $request->validated('operation_key') === null ? null : (string) $request->validated('operation_key'),
        );

        return AdminResponse::success(['readiness' => $this->service->readiness($set->refresh())]);
    }

    public function markNotApplicable(MarkExecutiveDocumentRequirementNotApplicableRequest $request, ExecutiveDocumentRequirement $requirement): JsonResponse
    {
        $user = $request->user();
        $updated = $this->service->markNotApplicable($requirement, $user, (string) $request->validated('reason'), app(AuthorizationService::class), (int) $request->validated('expected_revision'));

        return AdminResponse::success($updated);
    }

    public function attachEvidence(AttachExecutiveDocumentRequirementEvidenceRequest $request, ExecutiveDocumentRequirement $requirement): JsonResponse
    {
        $updated = $this->service->attachEvidence($requirement, (int) $request->validated('version_id'), (array) $request->validated('coverage'), $request->user(), app(AuthorizationService::class), (int) $request->validated('expected_revision'));

        return AdminResponse::success($updated);
    }

    public function markApplicable(MarkExecutiveDocumentRequirementNotApplicableRequest $request, ExecutiveDocumentRequirement $requirement): JsonResponse
    {
        $updated = $this->service->markApplicable($requirement, $request->user(), (string) $request->validated('reason'), app(AuthorizationService::class), (int) $request->validated('expected_revision'));

        return AdminResponse::success($updated);
    }

    public function conditions(UpdateExecutiveDocumentRequirementConditionsRequest $request, ExecutiveDocumentRequirement $requirement): JsonResponse
    {
        $updated = $this->service->updateConditions(
            $requirement,
            (array) $request->validated('conditions'),
            (string) $request->validated('reason'),
            $request->user(),
            app(AuthorizationService::class),
            (int) $request->validated('expected_revision'),
        );

        return AdminResponse::success($updated);
    }
}
