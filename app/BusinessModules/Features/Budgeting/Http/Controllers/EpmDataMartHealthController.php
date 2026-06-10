<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Services\EpmDataMartHealthService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

use function is_numeric;
use function is_object;
use function trans_message;

final class EpmDataMartHealthController extends BudgetingAdminController
{
    public function __construct(
        private readonly EpmDataMartHealthService $service,
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

    private function inputWithContext(Request $request): array
    {
        $input = $request->query();
        $currentProjectId = $this->currentProjectId($request);

        if ($currentProjectId !== null && !array_key_exists('current_project_id', $input)) {
            $input['current_project_id'] = $currentProjectId;
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
}
