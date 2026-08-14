<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionDocumentInput;
use Closure;
use InvalidArgumentException;

final class ArbitrationInputBuilder
{
    public const PROMPT_CONTRACT = 'document-arbitration:v3';

    /**
     * @param  array<string,AiRoleRunResult>  $observerRuns
     * @return array{input:VisionDocumentInput,claims:list<ObservationClaim>}
     */
    public function build(VisionDocumentInput $source, array $observerRuns, Closure $onPhysicalAttemptReserved): array
    {
        $roles = array_keys($observerRuns);
        if (! in_array($roles, [
            ['observer_literal', 'observer_construction'],
            ['observer_literal', 'observer_construction', 'observer_risk'],
        ], true)) {
            throw new InvalidArgumentException('arbitration_requires_independent_observers');
        }
        $claims = $this->claims($source, $observerRuns);
        $compact = array_map(static fn (ObservationClaim $claim): array => [
            'id' => $claim->id,
            'role' => $claim->observerRole,
            'entity_key' => mb_substr($claim->entityKey, 0, 120),
            'fact_type' => mb_substr($claim->factType, 0, 120),
            'value' => $claim->value,
            'unit' => $claim->unit,
            'evidence_ref' => $claim->evidenceRef,
            'explicit_evidence' => $claim->explicitEvidence,
            'locator' => $claim->locator,
        ], $claims);
        $metadata = [
            'arbitration' => [
                'contract' => self::PROMPT_CONTRACT,
                'source_version' => $source->sourceVersion,
                'minority_evidence_required' => true,
                'claims' => $compact,
            ],
        ];

        return [
            'input' => new VisionDocumentInput(
                organizationId: $source->organizationId,
                projectId: $source->projectId,
                sessionId: $source->sessionId,
                documentId: $source->documentId,
                pageId: $source->pageId,
                pageNumber: $source->pageNumber,
                processingUnitId: $source->processingUnitId,
                sourceVersion: $source->sourceVersion,
                derivativeHash: $source->derivativeHash,
                contentType: $source->contentType,
                imageContent: $source->imageContent,
                imageDetail: $source->imageDetail,
                operationContext: new AiOperationContext(
                    AiOperationContext::deterministicId('arbitration-correlation|'.$source->operationContext->correlationId),
                    AiOperationContext::deterministicId('arbitration-attempt|'.$source->operationContext->attemptId),
                    $source->organizationId,
                    $source->projectId,
                    $source->sessionId,
                    $source->operationContext->stage,
                    'vision',
                    1,
                    $source->documentId,
                    $source->pageId,
                    $source->processingUnitId,
                ),
                sourceTransform: $source->sourceTransform,
                sheetRole: $source->sheetRole,
                nativeReferences: $source->nativeReferences,
                auxiliaryText: $source->auxiliaryText,
                auxiliaryMetadata: $metadata,
                regionImages: $source->regionImages,
                onPhysicalAttemptReserved: $onPhysicalAttemptReserved,
            ),
            'claims' => $claims,
        ];
    }

    /**
     * @param  array<string,AiRoleRunResult>  $observerRuns
     * @return list<ObservationClaim>
     */
    public function claims(VisionDocumentInput $source, array $observerRuns): array
    {
        $roles = array_keys($observerRuns);
        if ($roles === [] || ! array_is_list($roles)
            || array_diff($roles, ['observer_literal', 'observer_construction', 'observer_risk']) !== []) {
            throw new InvalidArgumentException('observer_roles_invalid');
        }
        $claims = [];
        foreach ($roles as $role) {
            $payload = $observerRuns[$role]->payload;
            $origin = $payload['source'] ?? null;
            if (! is_array($origin)
                || ($payload['role'] ?? null) !== $role
                || ($origin['document_id'] ?? null) !== $source->documentId
                || ($origin['page_id'] ?? null) !== $source->pageId
                || ($origin['source_version'] ?? null) !== $source->sourceVersion
                || ! is_array($payload['claims'] ?? null)
                || ! is_array($payload['evidence'] ?? null)) {
                throw new InvalidArgumentException('arbitration_observer_scope_invalid');
            }
            $evidence = [];
            foreach ($payload['evidence'] as $item) {
                if (! is_array($item) || ! is_string($item['key'] ?? null) || ! is_array($item['locator'] ?? null)) {
                    throw new InvalidArgumentException('arbitration_observer_evidence_invalid');
                }
                $key = str_replace('observer_', '', $role).':'.$item['key'];
                $evidence[$key] = [
                    ...$item['locator'],
                    'organization_id' => $source->organizationId,
                    'project_id' => $source->projectId,
                    'session_id' => $source->sessionId,
                ];
            }
            foreach ($payload['claims'] as $index => $claim) {
                if (! is_array($claim) || count($claims) >= 192) {
                    throw new InvalidArgumentException('arbitration_claims_unbounded');
                }
                if (is_string($claim['evidenceRef'] ?? null)) {
                    $claim['evidenceRef'] = str_replace('observer_', '', $role).':'.$claim['evidenceRef'];
                }
                $claims[] = ObservationClaim::fromObserverPayload(
                    $role,
                    (int) $index,
                    $claim,
                    $evidence,
                    $source->organizationId,
                    $source->projectId,
                    $source->sessionId,
                    $source->sourceVersion,
                );
            }
        }
        if ($claims === []) {
            throw new InvalidArgumentException('arbitration_claims_missing');
        }

        return $claims;
    }
}
