<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\CfoCommandCenterRequest;
use App\BusinessModules\Features\Budgeting\Services\CfoCommandCenterService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class CfoCommandCenterController extends BudgetingAdminController
{
    public function __construct(
        private readonly CfoCommandCenterService $service,
    ) {
    }

    public function show(CfoCommandCenterRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->dashboard($this->inputWithContext($request)),
                trans_message('budgeting.cfo_command_center.loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('budgeting.cfo_command_center.api_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'path' => $request->path(),
                'exception_class' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('budgeting.cfo_command_center.load_error'), 500);
        }
    }

    private function inputWithContext(Request $request): array
    {
        $input = $request instanceof CfoCommandCenterRequest ? $request->validated() : $request->query();
        $input['current_organization_id'] = $request->attributes->get('current_organization_id')
            ?? $input['current_organization_id']
            ?? null;

        $currentProjectId = $this->currentProjectId($request);

        if ($currentProjectId !== null && !array_key_exists('current_project_id', $input)) {
            $input['current_project_id'] = $currentProjectId;
        }

        return $input;
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
