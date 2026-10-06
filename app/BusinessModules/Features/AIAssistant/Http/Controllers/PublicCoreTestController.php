<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\PublicCoreTestRequest;
use App\BusinessModules\Features\AIAssistant\Http\Resources\PublicCoreRuntimeResource;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRequestService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class PublicCoreTestController extends Controller
{
    public function __construct(private readonly PublicCoreRequestService $requests)
    {
    }

    public function readiness(Request $request): JsonResponse
    {
        $this->assertEmptyInput($request);
        [$viewer, $organizationId] = $this->viewer($request);

        return AdminResponse::success(new PublicCoreRuntimeResource($this->requests->readiness($viewer, $organizationId)));
    }

    public function submit(PublicCoreTestRequest $request): JsonResponse
    {
        [$viewer, $organizationId] = $this->viewer($request);

        return AdminResponse::success(new PublicCoreRuntimeResource(
            $this->requests->submit($viewer, $organizationId, $request->validated())));
    }

    public function poll(Request $request, string $request_ref): JsonResponse
    {
        $this->assertEmptyInput($request);
        [$viewer, $organizationId] = $this->viewer($request);

        return AdminResponse::success(new PublicCoreRuntimeResource($this->requests->poll($viewer, $organizationId, $request_ref)));
    }

    private function viewer(Request $request): array
    {
        $viewer = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$viewer instanceof User || !is_int($organizationId) || $organizationId <= 0) {
            throw new AuthorizationException(trans_message('ai_assistant.access_denied'));
        }

        return [$viewer, $organizationId];
    }

    private function assertEmptyInput(Request $request): void
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['input' => trans_message('ai_assistant.request_invalid')]);
        }
    }
}
