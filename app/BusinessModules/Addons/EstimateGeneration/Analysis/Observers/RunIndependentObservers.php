<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Observers;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\AiRoleRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunClaim;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunFailure;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Contracts\VisionProvider;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionProviderException;
use RuntimeException;

final readonly class RunIndependentObservers
{
    public function __construct(
        private AiRoleRunRepository $runs,
        private VisionProvider $vision,
        private ObserverInputBuilder $inputs,
        private string $model,
    ) {
        if (preg_match('#^[A-Za-z0-9._/-]{1,160}$#D', $model) !== 1) {
            throw new \InvalidArgumentException('observer_model_invalid');
        }
    }

    /** @return array<string, AiRoleRunResult> */
    public function run(VisionDocumentInput $source): array
    {
        $results = [];
        foreach (ObserverProfile::cases() as $profile) {
            $ownerUuid = AiOperationContext::deterministicId(implode('|', [
                'observer-owner',
                $source->operationContext->attemptId,
                $profile->value,
                bin2hex(random_bytes(16)),
            ]));
            $physicalAttemptId = null;
            $claim = null;
            $observerInput = $this->inputs->build(
                $source,
                $profile,
                function (string $attemptId) use (&$physicalAttemptId, &$claim): void {
                    if (! $claim instanceof AiRoleRunClaim || $claim->ownerUuid === null) {
                        throw new UsageInvariantViolation('Observer physical attempt was reserved without an owned role run.');
                    }
                    $this->runs->startPhysicalAttempt($claim->runId, $claim->ownerUuid, $attemptId);
                    $physicalAttemptId = $attemptId;
                },
            );
            $runInput = $this->runInput($observerInput, $profile);
            $claim = $this->runs->claim($runInput, $ownerUuid);
            if ($claim->disposition === 'replay' && $claim->result !== null) {
                $results[$profile->role()->value] = $claim->result;

                continue;
            }
            if ($claim->disposition !== 'owned' || $claim->ownerUuid === null) {
                throw new RuntimeException('observer_role_run_'.$claim->disposition);
            }
            try {
                $analysis = $this->vision->analyze($observerInput);
                if ($physicalAttemptId === null) {
                    $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                        code: 'observer_physical_attempt_missing',
                        ambiguous: true,
                    ));
                    throw new UsageInvariantViolation('Observer provider returned without a physical attempt identity.');
                }
                $result = new AiRoleRunResult(
                    $this->resultPayload($analysis, $profile, $observerInput),
                    $physicalAttemptId,
                );
                $this->runs->complete($claim->runId, $claim->ownerUuid, $result);
                $results[$profile->role()->value] = $result;
            } catch (VisionContractException|VisionProviderException $exception) {
                $this->runs->fail($claim->runId, $claim->ownerUuid, new AiRoleRunFailure(
                    code: $exception instanceof VisionProviderException ? $exception->reason : 'observer_contract_invalid',
                    ambiguous: $exception instanceof VisionProviderException && $exception->reason === 'vision_wire_outcome_ambiguous',
                    physicalAttemptId: $physicalAttemptId,
                ));

                throw $exception;
            }
        }

        return $results;
    }

    private function runInput(VisionDocumentInput $input, ObserverProfile $profile): AiRoleRunInput
    {
        return new AiRoleRunInput(
            organizationId: $input->organizationId,
            projectId: $input->projectId,
            sessionId: $input->sessionId,
            documentId: $input->documentId,
            pageId: $input->pageId,
            subjectType: 'document_page',
            subjectId: (string) $input->pageId,
            subjectVersion: $input->sourceVersion,
            role: $profile->role(),
            model: $this->model,
            promptContractVersion: $profile->promptContractVersion(),
            inputFingerprint: hash('sha256', json_encode([
                'derivative_hash' => $input->derivativeHash,
                'native_references' => $input->nativeReferences,
                'auxiliary_text_sha256' => hash('sha256', $input->auxiliaryText ?? ''),
                'observer' => $input->auxiliaryMetadata['observer'] ?? null,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        );
    }

    /** @return array<string, mixed> */
    private function resultPayload(
        VisionAnalysisData $analysis,
        ObserverProfile $profile,
        VisionDocumentInput $input,
    ): array {
        return [
            'schema_version' => 1,
            'role' => $profile->role()->value,
            'prompt_contract' => $profile->promptContractVersion(),
            'source' => [
                'document_id' => $input->documentId,
                'page_id' => $input->pageId,
                'page_number' => $input->pageNumber,
                'source_version' => $input->sourceVersion,
            ],
            'observation' => [
                'sheet_type' => $analysis->sheetType,
                'elements' => array_slice($analysis->toArray()['elements'], 0, 64),
                'visual_attributes' => $analysis->visualAttributes,
                'warnings' => $analysis->warnings,
                'quarantined_items' => array_slice($analysis->quarantinedItems, 0, 64),
                'raw_facts' => $analysis->rawObserverFacts,
            ],
            'claims' => array_slice($analysis->projectSheetAnalysis?->facts ?? [], 0, 64),
            'evidence' => array_slice(array_map(
                static fn ($evidence): array => $evidence->toArray(),
                $analysis->evidence,
            ), 0, 128),
        ];
    }
}
