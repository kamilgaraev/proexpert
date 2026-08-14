<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Synthesis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final readonly class RunProjectSynthesis implements ProjectSynthesisRunner
{
    public const PROMPT_CONTRACT = 'project-synthesis:v1';

    public function __construct(
        private AiRoleRunRepository $runs,
        private ProjectSynthesisModel $model,
        private string $modelName,
        private ProjectSynthesisValidator $validator = new ProjectSynthesisValidator,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidateLinks
     * @param  list<array<string, mixed>>  $candidateQuestions
     */
    public function run(
        ProjectSynthesisInput $input,
        array $candidateLinks,
        array $candidateQuestions,
    ): ProjectSynthesisSelection {
        $validatedCandidates = $this->validator->validate([
            'links' => $candidateLinks,
            'questions' => $candidateQuestions,
        ], $input);
        $candidateLinks = $validatedCandidates['links'];
        $candidateQuestions = $validatedCandidates['questions'];
        $runInput = new AiRoleRunInput(
            organizationId: $input->organizationId,
            projectId: $input->projectId,
            sessionId: $input->sessionId,
            documentId: null,
            pageId: null,
            subjectType: 'estimate_session',
            subjectId: (string) $input->sessionId,
            subjectVersion: $input->aggregateSourceVersion(),
            role: AiAnalysisRole::ProjectEngineer,
            model: $this->modelName,
            promptContractVersion: self::PROMPT_CONTRACT,
            inputFingerprint: $input->fingerprint(),
        );
        $owner = AiOperationContext::deterministicId('project-synthesis-owner|'.$input->fingerprint().'|'.bin2hex(random_bytes(16)));
        $claim = $this->runs->claim($runInput, $owner);
        if ($claim->disposition === 'replay' && $claim->result !== null) {
            return $this->validateSelection(
                ProjectSynthesisSelection::fromArray($claim->result->payload['result'] ?? []),
                $candidateLinks,
                $candidateQuestions,
            );
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('project_engineer_role_run_'.$claim->disposition);
        }
        $physicalAttemptId = null;
        try {
            $raw = $this->model->synthesize(
                $input,
                $candidateLinks,
                $candidateQuestions,
                function (string $attemptId) use ($claim, &$physicalAttemptId): void {
                    $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId);
                    $physicalAttemptId = $attemptId;
                },
            );
            if ($physicalAttemptId === null) {
                throw new UsageInvariantViolation('Project engineer returned without a physical attempt identity.');
            }
            $selection = $this->validateSelection(
                ProjectSynthesisSelection::fromArray($raw),
                $candidateLinks,
                $candidateQuestions,
            );
            $this->runs->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult([
                'schema_version' => 1,
                'role' => AiAnalysisRole::ProjectEngineer->value,
                'result' => $selection->toArray(),
            ], $physicalAttemptId));

            return $selection;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                code: 'project_engineer_failed',
                ambiguous: $physicalAttemptId !== null,
                physicalAttemptId: $physicalAttemptId,
            ));

            throw $exception;
        }
    }

    /** @param list<array<string, mixed>> $links @param list<array<string, mixed>> $questions */
    private function validateSelection(
        ProjectSynthesisSelection $selection,
        array $links,
        array $questions,
    ): ProjectSynthesisSelection {
        $linkIds = [];
        foreach ($links as $link) {
            if (is_string($link['id'] ?? null)) {
                $linkIds[$link['id']] = true;
            }
        }
        $conflictIds = [];
        foreach ($questions as $question) {
            if (is_string($question['conflict_id'] ?? null)) {
                $conflictIds[$question['conflict_id']] = true;
            }
        }
        foreach ($selection->acceptedLinkIds as $id) {
            if (! isset($linkIds[$id])) {
                throw new InvalidArgumentException('project_synthesis_invented_link');
            }
        }
        foreach ($selection->questionConflictIds as $id) {
            if (! isset($conflictIds[$id])) {
                throw new InvalidArgumentException('project_synthesis_invented_question');
            }
        }
        if (array_diff(array_keys($conflictIds), $selection->questionConflictIds) !== []) {
            throw new InvalidArgumentException('project_synthesis_question_suppressed');
        }
        foreach ($links as $link) {
            if (($link['status'] ?? null) === 'confirmed'
                && is_string($link['id'] ?? null)
                && ! in_array($link['id'], $selection->acceptedLinkIds, true)) {
                throw new InvalidArgumentException('project_synthesis_confirmed_link_suppressed');
            }
        }

        return $selection;
    }
}
