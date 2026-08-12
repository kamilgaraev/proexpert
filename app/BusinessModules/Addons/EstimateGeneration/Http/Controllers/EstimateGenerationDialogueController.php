<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\ApplyEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\CancelEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ApplyEstimateChangeProposalRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\CancelEstimateChangeProposalRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\InterpretEstimateCommandRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ListEstimateChangeProposalItemsRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateChangeProposalResource;
use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class EstimateGenerationDialogueController extends Controller
{
    public function __construct(private readonly InterpretEstimateCommand $interpret, private readonly ApplyEstimateChangeProposal $apply, private readonly CancelEstimateChangeProposal $cancel, private readonly EstimateChangeProposalRepository $proposals) {}

    public function interpret(InterpretEstimateCommandRequest $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safe(function () use ($request, $project, $session): array {
            $this->guard($request, $project, $session);
            $result = $this->interpret->handle($session, (int) $request->user()->id, (string) $request->validated('command'), (string) $request->validated('idempotency_key'));
            if (($result['kind'] ?? null) === 'proposal') {
                $result['proposal'] = (new EstimateChangeProposalResource($result['proposal']))->resolve($request);
            }

            return $result;
        }, $session);
    }

    public function show(Request $request, Project $project, EstimateGenerationSession $session, string $proposal): JsonResponse
    {
        return $this->safe(function () use ($request, $project, $session, $proposal): array {
            $this->guard($request, $project, $session);

            return (new EstimateChangeProposalResource($this->proposals->find($proposal, (int) $session->organization_id, (int) $project->id, (int) $session->id)->payload))->resolve($request);
        }, $session);
    }

    public function items(ListEstimateChangeProposalItemsRequest $request, Project $project, EstimateGenerationSession $session, string $proposal): JsonResponse
    {
        return $this->safe(function () use ($request, $project, $session, $proposal): array {
            $this->guard($request, $project, $session);
            $this->proposals->find($proposal, (int) $session->organization_id, (int) $project->id, (int) $session->id);

            return $this->proposals->items($proposal, (int) ($request->validated('limit') ?? 50), $request->validated('cursor') !== null ? (int) $request->validated('cursor') : null);
        }, $session);
    }

    public function apply(ApplyEstimateChangeProposalRequest $request, Project $project, EstimateGenerationSession $session, string $proposal): JsonResponse
    {
        return $this->safe(function () use ($request, $project, $session, $proposal): array {
            $this->guard($request, $project, $session);

            return (new EstimateChangeProposalResource($this->apply->handle($request->user(), (int) $session->organization_id, (int) $project->id, (int) $session->id, $proposal, (int) $request->validated('state_version'))->payload))->resolve($request);
        }, $session);
    }

    public function cancel(CancelEstimateChangeProposalRequest $request, Project $project, EstimateGenerationSession $session, string $proposal): JsonResponse
    {
        return $this->safe(function () use ($request, $project, $session, $proposal): array {
            $this->guard($request, $project, $session);

            return (new EstimateChangeProposalResource($this->cancel->handle((int) $request->user()->id, (int) $session->organization_id, (int) $project->id, (int) $session->id, $proposal)->payload))->resolve($request);
        }, $session);
    }

    private function guard(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        if ((int) $session->organization_id !== (int) $request->user()->current_organization_id || (int) $session->project_id !== (int) $project->id) {
            abort(403, trans_message('estimate_generation.access_denied'));
        }
    }

    /** @param callable(): array<string, mixed> $callback */
    private function safe(callable $callback, EstimateGenerationSession $session): JsonResponse
    {
        try {
            return AdminResponse::success($callback());
        } catch (RuntimeException $exception) {
            $code = str_contains($exception->getMessage(), 'not_found') ? 404 : (str_contains($exception->getMessage(), 'invalid') || str_contains($exception->getMessage(), 'collision') ? 422 : 409);
            $messageKey = str_starts_with($exception->getMessage(), 'estimate_generation.') ? $exception->getMessage() : 'estimate_generation.state_conflict';

            return AdminResponse::error(trans_message($messageKey), $code);
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Dialogue failed', ['session_id' => $session->id, 'failure_code' => 'estimate_dialogue_failed']);

            return AdminResponse::error(trans_message('estimate_generation.dialogue_error'), 500);
        }
    }
}
