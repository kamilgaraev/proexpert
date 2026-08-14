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
            return ProjectSynthesisSelection::fromArray($claim->result->payload['result'] ?? []);
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
            $projection = $this->projectSelection($raw, $candidateLinks, $candidateQuestions);
            $selection = $projection['selection'];
            $this->runs->complete($claim->runId, $claim->ownerUuid, new AiRoleRunResult([
                'schema_version' => 1,
                'role' => AiAnalysisRole::ProjectEngineer->value,
                'result' => $selection->toArray(),
                'quarantined_intents' => $projection['quarantined'],
                'result_state' => $selection->questionConflictIds !== []
                    ? 'questions'
                    : ($projection['quarantined'] === [] ? 'ready' : 'partial'),
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

    /**
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $questions
     * @return array{selection:ProjectSynthesisSelection,quarantined:list<array{field:string,index:int,reason:string}>}
     */
    private function projectSelection(
        mixed $payload,
        array $links,
        array $questions,
    ): array {
        if (! is_array($payload) || array_is_list($payload)
            || ! is_array($payload['accepted_link_ids'] ?? null)
            || ! array_is_list($payload['accepted_link_ids'])
            || count($payload['accepted_link_ids']) > 10000
            || ! is_array($payload['question_conflict_ids'] ?? null)
            || ! array_is_list($payload['question_conflict_ids'])
            || count($payload['question_conflict_ids']) > 10000) {
            throw new InvalidArgumentException('project_synthesis_selection_invalid');
        }
        $linkIds = [];
        $confirmedLinkIds = [];
        foreach ($links as $link) {
            if (is_string($link['id'] ?? null)) {
                $linkIds[$link['id']] = true;
                if (($link['status'] ?? null) === 'confirmed') {
                    $confirmedLinkIds[$link['id']] = true;
                }
            }
        }
        $conflictIds = [];
        foreach ($questions as $question) {
            if (is_string($question['conflict_id'] ?? null)) {
                $conflictIds[$question['conflict_id']] = true;
            }
        }
        $acceptedLinkIds = $confirmedLinkIds;
        $quarantined = [];
        foreach ($payload['accepted_link_ids'] as $index => $id) {
            if (! is_string($id) || ! isset($linkIds[$id])) {
                $quarantined[] = ['field' => 'accepted_link_ids', 'index' => $index, 'reason' => 'project_synthesis_invented_link'];

                continue;
            }
            $acceptedLinkIds[$id] = true;
        }
        foreach ($payload['question_conflict_ids'] as $index => $id) {
            if (! is_string($id) || ! isset($conflictIds[$id])) {
                $quarantined[] = ['field' => 'question_conflict_ids', 'index' => $index, 'reason' => 'project_synthesis_invented_question'];
            }
        }

        return [
            'selection' => new ProjectSynthesisSelection(array_keys($acceptedLinkIds), array_keys($conflictIds)),
            'quarantined' => $quarantined,
        ];
    }
}
