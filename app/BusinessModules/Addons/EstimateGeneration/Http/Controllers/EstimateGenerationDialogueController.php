<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\ApplyEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\CancelEstimateChangeProposal;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\InterpretEstimateCommandFailure;
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
        } catch (InterpretEstimateCommandFailure $exception) {
            [$machineCode, $messageKey, $code] = $this->publicError($exception);

            return AdminResponse::error(trans_message($messageKey), $code, null, [
                'code' => $machineCode,
                'retry_disposition' => $exception->retryDisposition(),
            ]);
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            [$machineCode, $messageKey, $code] = $this->publicError($exception);
            $retryDisposition = $this->publicRetryDisposition($machineCode);

            return AdminResponse::error(trans_message($messageKey), $code, null, array_filter([
                'code' => $machineCode,
                'retry_disposition' => $retryDisposition,
            ], static fn (mixed $value): bool => $value !== null));
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Dialogue failed', ['session_id' => $session->id, 'failure_code' => 'estimate_dialogue_failed']);

            return AdminResponse::error(trans_message('estimate_generation.dialogue_error'), 500);
        }
    }

    /** @return array{string,string,int} */
    private function publicError(\Throwable $exception): array
    {
        $raw = $exception->getMessage();
        $code = str_starts_with($raw, 'estimate_generation.')
            ? explode(':', substr($raw, strlen('estimate_generation.')), 2)[0]
            : 'state_conflict';
        $map = [
            'command_intent_invalid' => ['estimate_generation.command_intent_invalid', 422],
            'command_provider_invalid' => ['estimate_generation.command_provider_invalid', 502],
            'command_context_review_required' => ['estimate_generation.command_context_review_required', 422],
            'command_reference_invalid' => ['estimate_generation.command_reference_invalid', 422],
            'interpretation_attempt_active' => ['estimate_generation.interpretation_attempt_active', 409],
            'interpretation_attempt_lost' => ['estimate_generation.interpretation_attempt_lost', 409],
            'interpretation_attempt_ambiguous' => ['estimate_generation.interpretation_attempt_ambiguous', 409],
            'interpretation_attempt_expired' => ['estimate_generation.interpretation_attempt_expired', 409],
            'interpretation_response_invalid' => ['estimate_generation.interpretation_response_invalid', 409],
            'interpretation_response_collision' => ['estimate_generation.interpretation_response_collision', 409],
            'interpretation_publication_failed' => ['estimate_generation.interpretation_publication_failed', 500],
            'interpretation_completion_collision' => ['estimate_generation.interpretation_completion_collision', 409],
            'proposal_idempotency_collision' => ['estimate_generation.proposal_idempotency_collision', 422],
            'proposal_not_found' => ['estimate_generation.proposal_not_found', 404],
            'proposal_stale' => ['estimate_generation.proposal_stale', 409],
            'proposal_expired' => ['estimate_generation.proposal_expired', 409],
            'proposal_terminal' => ['estimate_generation.proposal_terminal', 409],
            'proposal_concurrent' => ['estimate_generation.proposal_concurrent', 409],
            'proposal_too_large' => ['estimate_generation.proposal_too_large', 422],
            'proposal_payload_invalid' => ['estimate_generation.proposal_payload_invalid', 422],
            'proposal_intent_unsupported' => ['estimate_generation.proposal_intent_unsupported', 422],
            'locator_invalid' => ['estimate_generation.locator_invalid', 422],
        ];
        [$key, $status] = $map[$code] ?? ['estimate_generation.state_conflict', 409];

        return [$code, $key, $status];
    }

    private function publicRetryDisposition(string $machineCode): ?string
    {
        if (in_array($machineCode, [
            'command_intent_invalid',
            'command_context_review_required',
            'command_reference_invalid',
            'proposal_idempotency_collision',
            'proposal_payload_invalid',
            'proposal_intent_unsupported',
            'locator_invalid',
        ], true)) {
            return 'payload_invalid';
        }
        if (in_array($machineCode, ['proposal_stale', 'proposal_expired', 'proposal_terminal'], true)) {
            return 'terminal_new_attempt_allowed';
        }

        return null;
    }
}
