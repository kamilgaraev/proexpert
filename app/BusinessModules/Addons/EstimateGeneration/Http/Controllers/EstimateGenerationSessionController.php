<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionOperationalSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\CreateEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotEtag;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Enums\EstimateGenerationMode;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CreateEstimateGenerationSessionRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionListResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationRegionalContextResolver;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

use function trans_message;

final class EstimateGenerationSessionController extends Controller
{
    private const SNAPSHOT_CACHE_CONTROL = 'private, no-cache, must-revalidate';

    public function __construct(
        private readonly CreateEstimateGenerationSession $createSession,
        private readonly EstimateGenerationRegionalContextResolver $regionalContextResolver,
        private readonly BuildSessionOperationalSnapshot $operationalSnapshot,
    ) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        return $this->safeReadResponse(function () use ($request, $project): JsonResponse {
            $sessions = EstimateGenerationSession::query()
                ->where('organization_id', $request->user()->current_organization_id)
                ->where('project_id', $project->id)
                ->orderByDesc('id')
                ->paginate((int) $request->input('per_page', 10));

            return AdminResponse::paginated(
                EstimateGenerationSessionListResource::collection($sessions),
                [
                    'current_page' => $sessions->currentPage(),
                    'last_page' => $sessions->lastPage(),
                    'per_page' => $sessions->perPage(),
                    'total' => $sessions->total(),
                ],
            );
        }, 'list sessions', ['project_id' => $project->id]);
    }

    public function store(CreateEstimateGenerationSessionRequest $request, Project $project): JsonResponse
    {
        try {
            $validated = $request->validated();
            $generationMode = EstimateGenerationMode::fromInput($validated['generation_mode'] ?? null)->value;
            $session = $this->createSession->handle([
                'organization_id' => $request->user()->current_organization_id,
                'project_id' => $project->id,
                'user_id' => $request->user()->id,
                'status' => EstimateGenerationStatus::Draft->value,
                'processing_stage' => 'draft',
                'processing_progress' => 0,
                'input_payload' => array_merge($validated, [
                    'generation_mode' => $generationMode,
                    'parameters' => $validated['parameters'] ?? [],
                    'regional_context' => $this->regionalContextResolver->resolve($validated),
                ]),
                'problem_flags' => [],
            ]);

            return AdminResponse::success(
                (new EstimateGenerationSessionResource($session->load('documents')))->resolve(),
                trans_message('estimate_generation.session_created'),
                201,
            );
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Failed to create session', [
                'failure_code' => 'session_create_failed',
                'project_id' => $project->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.session_create_error'), 500);
        }
    }

    public function show(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safeReadResponse(function () use ($request, $project, $session): JsonResponse {
            $this->guardSession($request, $project, $session);
            $session->loadMissing([
                'documents' => static fn ($query) => $query
                    ->withCount(['pages', 'facts', 'drawingElements', 'quantityTakeoffs', 'scopeInferences'])
                    ->orderBy('id'),
            ]);

            return AdminResponse::success((new EstimateGenerationSessionResource($session))->resolve());
        }, 'show session', ['project_id' => $project->id, 'session_id' => $session->id]);
    }

    public function snapshot(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse|Response
    {
        try {
            $this->guardSession($request, $project, $session);
            $snapshot = $this->operationalSnapshot->handle(
                $session,
                EstimateGenerationSessionListResource::permissions($request, $session),
            );
            $etag = SessionSnapshotEtag::forRevision(
                (int) $session->organization_id,
                (int) $session->getKey(),
                $snapshot->operationalVersion,
            );
            $headers = [
                'ETag' => $etag,
                'Cache-Control' => self::SNAPSHOT_CACHE_CONTROL,
                'Vary' => 'Authorization, If-None-Match',
            ];

            if (SessionSnapshotEtag::matches($request->header('If-None-Match'), $etag)) {
                return response('', 304, $headers);
            }

            $response = AdminResponse::success($snapshot->toArray());
            $response->headers->add($headers);

            return $response;
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Snapshot failed', [
                'failure_code' => 'snapshot_failed',
                'project_id' => $project->id,
                'session_id' => $session->id,
            ]);

            return AdminResponse::error(trans_message('estimate_generation.read_error'), 500);
        }
    }

    private function guardSession(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        if (
            (int) $session->organization_id !== (int) $request->user()->current_organization_id
            || (int) $session->project_id !== (int) $project->id
        ) {
            abort(403, trans_message('estimate_generation.access_denied'));
        }
    }

    /** @param callable(): JsonResponse $response @param array<string, mixed> $context */
    private function safeReadResponse(callable $response, string $operation, array $context): JsonResponse
    {
        try {
            return $response();
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Read endpoint failed', [
                ...$context,
                'operation' => $operation,
                'failure_code' => 'read_endpoint_failed',
            ]);

            return AdminResponse::error(trans_message('estimate_generation.read_error'), 500);
        }
    }
}
