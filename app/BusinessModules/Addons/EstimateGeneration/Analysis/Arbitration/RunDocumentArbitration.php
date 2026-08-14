<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use RuntimeException;
use Throwable;

final readonly class RunDocumentArbitration implements DocumentArbitrator
{
    public function __construct(
        private AiRoleRunRepository $runs,
        private VisionProvider $vision,
        private ArbitrationInputBuilder $inputs,
        private string $model,
        private ?ProjectModelEvidenceWriter $writer = null,
    ) {}

    /** @param array<string,AiRoleRunResult> $observerRuns */
    public function run(VisionDocumentInput $source, array $observerRuns): AiRoleRunResult
    {
        $claim = null;
        $physicalAttemptId = null;
        $built = $this->inputs->build($source, $observerRuns, function (string $attemptId) use (&$claim, &$physicalAttemptId): void {
            if (! $claim instanceof AiRoleRunClaim || $claim->ownerUuid === null) {
                throw new RuntimeException('arbitration_role_run_not_owned');
            }
            $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId);
            $physicalAttemptId = $attemptId;
        });
        $input = new AiRoleRunInput(
            $source->organizationId,
            $source->projectId,
            $source->sessionId,
            $source->documentId,
            $source->pageId,
            'document_page',
            (string) $source->pageId,
            $source->sourceVersion,
            AiAnalysisRole::Arbiter,
            $this->model,
            ArbitrationInputBuilder::PROMPT_CONTRACT,
            hash('sha256', json_encode(array_map(
                static fn (ObservationClaim $item): array => [$item->id, $item->factType, $item->value, $item->evidenceRef],
                $built['claims'],
            ), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        );
        $owner = AiOperationContext::deterministicId('arbiter-owner|'.bin2hex(random_bytes(16)));
        $claim = $this->runs->claim($input, $owner);
        if ($claim->disposition === 'replay' && $claim->result !== null) {
            return $claim->result;
        }
        if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
            throw new RuntimeException('arbiter_role_run_'.$claim->disposition);
        }
        try {
            $analysis = $this->vision->analyze($built['input']);
            if ($physicalAttemptId === null) {
                throw new RuntimeException('arbitration_physical_attempt_missing');
            }
            $decisions = array_map(
                static fn (array $intent): ArbitrationDecision => ArbitrationDecision::fromProviderIntent($intent, $built['claims']),
                $analysis->rawObserverFacts,
            );
            if ($decisions === []) {
                throw new RuntimeException('arbitration_decisions_missing');
            }
            $result = new AiRoleRunResult([
                'schema_version' => 3,
                'role' => AiAnalysisRole::Arbiter->value,
                'prompt_contract' => ArbitrationInputBuilder::PROMPT_CONTRACT,
                'source' => [
                    'document_id' => $source->documentId,
                    'page_id' => $source->pageId,
                    'page_number' => $source->pageNumber,
                    'source_version' => $source->sourceVersion,
                ],
                'decisions' => array_map(static fn (ArbitrationDecision $decision): array => [
                    'claim_id' => $decision->claimId,
                    'status' => $decision->status,
                    'supporting_claim_ids' => $decision->supportingClaimIds,
                    'evidence_refs' => $decision->evidenceRefs,
                    'reason_code' => $decision->reasonCode,
                    'canonical_claim' => $decision->canonicalClaim,
                    'question' => $decision->question,
                ], $decisions),
                'contract_repairs' => $analysis->contractRepairs,
                'questions' => array_values(array_filter(array_map(
                    static fn (ArbitrationDecision $decision): ?array => $decision->question,
                    $decisions,
                ))),
            ], $physicalAttemptId);
            $this->writer?->writeArbitration($built['claims'], $decisions, $source->documentId, $source->pageNumber);
            $this->runs->complete($claim->runId, $claim->ownerUuid, $result);

            return $result;
        } catch (Throwable $exception) {
            $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                $exception instanceof VisionProviderException ? $exception->reason : 'arbitration_contract_invalid',
                $exception instanceof VisionProviderException && $exception->reason === 'vision_wire_outcome_ambiguous',
                $physicalAttemptId,
            ));
            throw $exception;
        }
    }
}
