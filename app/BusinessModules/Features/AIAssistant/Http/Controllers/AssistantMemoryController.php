<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\StoreAssistantMemoryRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\UpdateAssistantMemoryRequest;
use App\BusinessModules\Features\AIAssistant\Http\Resources\AssistantMemoryResource;
use App\BusinessModules\Features\AIAssistant\Models\AssistantMemory;
use App\BusinessModules\Features\AIAssistant\Services\AssistantMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AssistantMemoryController extends AbstractAssistantApiController
{
    public function __construct(private readonly AssistantMemoryService $memories) {}

    public function index(Request $request): JsonResponse
    {
        return $this->success($request, AssistantMemoryResource::collection($this->memories->list($this->actor($request), $this->organizationId($request))));
    }

    public function store(StoreAssistantMemoryRequest $request): JsonResponse
    {
        try {
            $memory = $this->memories->create($this->actor($request), $this->organizationId($request), $request->validated());

            return $this->success($request, new AssistantMemoryResource($memory), 201);
        } catch (RuntimeException) {
            return $this->error($request, 403);
        }
    }

    public function update(UpdateAssistantMemoryRequest $request, AssistantMemory $memory): JsonResponse
    {
        if ((int) $memory->organization_id !== $this->organizationId($request) || (int) $memory->user_id !== (int) $this->actor($request)->id) {
            return $this->error($request, 404);
        }
        try {
            return $this->success($request, new AssistantMemoryResource($this->memories->update($this->actor($request), $this->organizationId($request), $memory, $request->validated())));
        } catch (RuntimeException) {
            return $this->error($request, 403);
        }
    }

    public function destroy(Request $request, AssistantMemory $memory): JsonResponse
    {
        if ((int) $memory->organization_id !== $this->organizationId($request) || (int) $memory->user_id !== (int) $this->actor($request)->id) {
            return $this->error($request, 404);
        }
        $this->memories->delete($this->actor($request), $this->organizationId($request), $memory);

        return $this->success($request);
    }
}
