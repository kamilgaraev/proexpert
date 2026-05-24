<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class AIAssistantRagController extends Controller
{
    public function __construct(
        private readonly RagIndexingCoordinator $coordinator
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId <= 0) {
            return AdminResponse::error(trans_message('ai_assistant.organization_not_found'), 400);
        }

        try {
            return AdminResponse::success(
                new RagIndexStatusResource($this->buildStatusPayload($organizationId)),
                trans_message('ai_assistant.rag_status_loaded')
            );
        } catch (Throwable $throwable) {
            Log::error('ai_assistant.rag.status_failed', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'exception_class' => $throwable::class,
            ]);

            return AdminResponse::error(trans_message('ai_assistant.rag_status_failed'), 500);
        }
    }

    public function reindex(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId <= 0) {
            return AdminResponse::error(trans_message('ai_assistant.organization_not_found'), 400);
        }

        $validated = $request->validate([
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where('organization_id', $organizationId),
            ],
            'source_type' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $run = $this->coordinator->queueOrganization(
                $organizationId,
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
                isset($validated['source_type']) ? trim((string) $validated['source_type']) : null,
                RagIndexRun::MODE_MANUAL
            );

            return AdminResponse::success([
                'queued' => true,
                'run' => RagIndexStatusResource::runPayload($run),
            ], trans_message('ai_assistant.rag_reindex_queued'));
        } catch (Throwable $throwable) {
            Log::error('ai_assistant.rag.reindex_failed', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'exception_class' => $throwable::class,
            ]);

            return AdminResponse::error(trans_message('ai_assistant.rag_reindex_failed'), 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStatusPayload(int $organizationId): array
    {
        $counts = $this->coordinator->countsForScope($organizationId);
        $latestRun = $this->latestRun($organizationId);
        $lastSuccessfulRun = $this->latestRun($organizationId, RagIndexRun::STATUS_SUCCEEDED);
        $lastFailedRun = $this->latestRun($organizationId, RagIndexRun::STATUS_FAILED);
        $enabled = (bool) config('ai-assistant.rag.enabled', false);

        return [
            'enabled' => $enabled,
            'ready' => $enabled && $counts['source_count'] > 0 && $counts['chunk_count'] > 0,
            'source_count' => $counts['source_count'],
            'chunk_count' => $counts['chunk_count'],
            'latest_run' => $latestRun,
            'last_successful_run' => $lastSuccessfulRun,
            'last_failed_run' => $lastFailedRun,
        ];
    }

    private function latestRun(int $organizationId, ?string $status = null): ?RagIndexRun
    {
        $query = RagIndexRun::query()
            ->forOrganization($organizationId)
            ->latestFirst();

        if ($status !== null) {
            $query->where('status', $status);
        }

        $run = $query->first();

        return $run instanceof RagIndexRun ? $run : null;
    }

    private function resolveOrganizationId(Request $request): int
    {
        $requestOrganizationId = (int) $request->attributes->get('current_organization_id', 0);
        if ($requestOrganizationId > 0) {
            return $requestOrganizationId;
        }

        $user = $request->user();

        if ($user instanceof User) {
            return (int) ($user->current_organization_id ?? 0);
        }

        return 0;
    }
}
