<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Controllers;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\AnswerEstimateClarificationRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateClarificationAnswerResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Questions\AnswerEstimateClarification;
use App\BusinessModules\Addons\EstimateGeneration\Questions\ListEstimateClarifications;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class EstimateGenerationQuestionController extends Controller
{
    public function __construct(
        private readonly ListEstimateClarifications $questions,
        private readonly AnswerEstimateClarification $answer,
    ) {}

    public function index(Request $request, Project $project, EstimateGenerationSession $session): JsonResponse
    {
        return $this->safe(function () use ($request, $project, $session): array {
            $this->guard($request, $project, $session);
            $items = $this->questions->handle(
                (int) $session->organization_id,
                (int) $project->id,
                (int) $session->id,
            );

            return ['items' => $items, 'count' => count($items)];
        }, $session);
    }

    public function answer(
        AnswerEstimateClarificationRequest $request,
        Project $project,
        EstimateGenerationSession $session,
        string $question,
    ): JsonResponse {
        return $this->safe(function () use ($request, $project, $session, $question): array {
            $this->guard($request, $project, $session);
            $result = $this->answer->handle(
                $request->user(),
                $session,
                new ActorContext(
                    (int) $session->organization_id,
                    (int) $project->id,
                    (int) $request->user()->id,
                    (string) $request->validated('idempotency_key'),
                    (string) $request->validated('expected_source_version'),
                    (string) $request->validated('answer_fingerprint'),
                ),
                $question,
                (string) $request->validated('response'),
                is_string($request->validated('other')) ? $request->validated('other') : null,
            );

            return (new EstimateClarificationAnswerResource($result))->resolve($request);
        }, $session);
    }

    private function guard(Request $request, Project $project, EstimateGenerationSession $session): void
    {
        if ((int) $session->organization_id !== (int) $request->user()->current_organization_id
            || (int) $session->project_id !== (int) $project->id) {
            throw new AuthorizationException(trans_message('estimate_generation.access_denied'));
        }
    }

    /** @param callable(): array<string,mixed> $callback */
    private function safe(callable $callback, EstimateGenerationSession $session): JsonResponse
    {
        try {
            return AdminResponse::success($callback());
        } catch (AuthorizationException) {
            return AdminResponse::error(trans_message('estimate_generation.access_denied'), 403);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            [$messageKey, $status] = $this->publicError($exception->getMessage());

            return AdminResponse::error(trans_message($messageKey), $status, null, [
                'code' => str_replace('estimate_generation.', '', $messageKey),
            ]);
        } catch (\Throwable) {
            Log::error('[EstimateGeneration] Clarification failed', [
                'session_id' => $session->id,
                'failure_code' => 'estimate_clarification_failed',
            ]);

            return AdminResponse::error(trans_message('estimate_generation.question_error'), 500);
        }
    }

    /** @return array{string,int} */
    private function publicError(string $code): array
    {
        return match ($code) {
            'estimate_generation.question_not_found' => ['estimate_generation.question_not_found', 404],
            'estimate_generation.question_stale',
            'estimate_generation.question_source_fact_missing' => ['estimate_generation.question_stale', 409],
            'estimate_generation.question_fence_required',
            'estimate_generation.question_response_invalid',
            'estimate_generation.question_other_invalid',
            'estimate_generation.question_idempotency_collision' => [$code, 422],
            default => ['estimate_generation.state_conflict', 409],
        };
    }
}
