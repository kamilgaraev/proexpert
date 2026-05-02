<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Http\Requests\ProjectPulse\GenerateProjectPulseRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\ProjectPulse\ProjectPulseReportRequest;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\ProjectPulseService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

class ProjectPulseController extends Controller
{
    public function __construct(
        private readonly ProjectPulseService $projectPulseService,
    ) {
    }

    public function current(ProjectPulseReportRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.organization_missing'), 400);
        }

        try {
            $context = ProjectPulseContext::fromValidated($request->validated(), $organizationId, $request->user()?->id);

            return AdminResponse::success(
                $this->projectPulseService->current($context),
                trans_message('ai_assistant.project_pulse.loaded')
            );
        } catch (Throwable $exception) {
            Log::error('project_pulse.current_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'query' => $request->query(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('ai_assistant.project_pulse.load_error'), 500);
        }
    }

    public function generate(GenerateProjectPulseRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.organization_missing'), 400);
        }

        try {
            $context = ProjectPulseContext::fromValidated($request->validated(), $organizationId, $request->user()?->id);

            return AdminResponse::success(
                $this->projectPulseService->generate($context),
                trans_message('ai_assistant.project_pulse.generated')
            );
        } catch (Throwable $exception) {
            Log::error('project_pulse.generate_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'payload' => $request->validated(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('ai_assistant.project_pulse.generate_error'), 500);
        }
    }

    public function reports(ProjectPulseReportRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.organization_missing'), 400);
        }

        try {
            $reports = $this->projectPulseService->list($organizationId, $request->validated());

            return AdminResponse::paginated(
                $reports->items(),
                [
                    'total' => $reports->total(),
                    'per_page' => $reports->perPage(),
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                ],
                trans_message('ai_assistant.project_pulse.loaded')
            );
        } catch (Throwable $exception) {
            Log::error('project_pulse.reports_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'query' => $request->query(),
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('ai_assistant.project_pulse.load_error'), 500);
        }
    }

    public function show(ProjectPulseReportRequest $request, ProjectPulseReport $report): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.organization_missing'), 400);
        }

        try {
            return AdminResponse::success(
                $this->projectPulseService->get($organizationId, $report),
                trans_message('ai_assistant.project_pulse.loaded')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.report_not_found'), 404);
        } catch (Throwable $exception) {
            Log::error('project_pulse.show_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('ai_assistant.project_pulse.load_error'), 500);
        }
    }

    public function destroy(ProjectPulseReportRequest $request, ProjectPulseReport $report): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        if ($organizationId === null) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.organization_missing'), 400);
        }

        try {
            $this->projectPulseService->delete($organizationId, $report);

            return AdminResponse::success(null, trans_message('ai_assistant.project_pulse.deleted'));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('ai_assistant.project_pulse.report_not_found'), 404);
        } catch (Throwable $exception) {
            Log::error('project_pulse.destroy_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $organizationId,
                'report_id' => $report->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('ai_assistant.project_pulse.delete_error'), 500);
        }
    }

    private function organizationId(ProjectPulseReportRequest|GenerateProjectPulseRequest $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
