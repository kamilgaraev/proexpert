<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\DTOs\EpmDataMartScope;
use App\BusinessModules\Features\Budgeting\Http\Requests\EpmDataMartRecalculationRequest;
use App\BusinessModules\Features\Budgeting\Models\EpmDataMartRecalculationRun;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartHealthService;
use App\BusinessModules\Features\Budgeting\Services\EpmDataMartRecalculationCoordinator;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

use function array_key_exists;
use function count;
use function is_numeric;
use function is_object;
use function is_string;
use function trim;
use function trans_message;

final class EpmDataMartHealthController extends BudgetingAdminController
{
    public function __construct(
        private readonly EpmDataMartHealthService $service,
        private readonly EpmDataMartRecalculationCoordinator $coordinator,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->status($this->organizationId($request), $this->inputWithContext($request)),
                trans_message('epm_data_mart.health.loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('budgeting.epm_data_mart.health.api_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'path' => $request->path(),
                'exception_class' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('epm_data_mart.health.load_error'), 500);
        }
    }

    public function recalculate(EpmDataMartRecalculationRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $input = $this->inputWithContext($request, [
                ...$request->validated(),
                'organization_id' => $organizationId,
                'current_organization_id' => $organizationId,
            ]);
            $runs = [];

            foreach ($this->requestedReportScopes($input['report_scope'] ?? null) as $reportScope) {
                $scope = EpmDataMartScope::fromInput($reportScope, $input);
                $runs[] = $this->runPayload($this->coordinator->queue($scope, $request->user()?->id));
            }

            return AdminResponse::success(
                [
                    'status' => 'queued',
                    'accepted_count' => count($runs),
                    'runs' => $runs,
                ],
                trans_message('epm_data_mart.health.recalculation_queued'),
                202,
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('budgeting.epm_data_mart.recalculation.api_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'path' => $request->path(),
                'exception_class' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('epm_data_mart.health.recalculation_error'), 500);
        }
    }

    private function inputWithContext(Request $request, ?array $input = null): array
    {
        $input ??= $request->query();
        $currentProjectId = $this->currentProjectId($request);

        if ($currentProjectId !== null && !array_key_exists('current_project_id', $input)) {
            $input['current_project_id'] = $currentProjectId;
        }

        if ($currentProjectId !== null && (!array_key_exists('project_id', $input) || $input['project_id'] === null || $input['project_id'] === '')) {
            $input['project_id'] = $currentProjectId;
        }

        return $input;
    }

    private function organizationId(Request $request): int
    {
        $value = $request->attributes->get('current_organization_id');

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new DomainException(trans_message('epm_data_mart.health.errors.organization_required'));
    }

    private function currentProjectId(Request $request): ?int
    {
        $value = $request->attributes->get('current_project_id');

        if (is_numeric($value) && (int) $value > 0) {
            return (int) $value;
        }

        $projectContext = $request->attributes->get('project_context');

        if (!is_object($projectContext)) {
            return null;
        }

        foreach (['project_id', 'projectId', 'resource_id', 'resourceId'] as $property) {
            if (isset($projectContext->{$property}) && is_numeric($projectContext->{$property})) {
                return (int) $projectContext->{$property};
            }
        }

        return null;
    }

    private function requestedReportScopes(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '' || trim($value) === 'all') {
            return EpmDataMartScope::SUPPORTED_REPORT_SCOPES;
        }

        return [EpmDataMartScope::normalizeReportScope($value)];
    }

    private function runPayload(EpmDataMartRecalculationRun $run): array
    {
        return [
            'id' => (int) $run->id,
            'report_scope' => (string) $run->report_scope,
            'scope_hash' => (string) $run->scope_hash,
            'status' => (string) $run->status,
        ];
    }
}
