<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ArbitrationDecision;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\ObservationClaim;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceAttribute;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceNode;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceProducer;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use InvalidArgumentException;

final readonly class ProjectModelEvidenceWriter
{
    public function __construct(
        private ProjectModelRepository $models,
        private EvidenceRepository $evidence,
    ) {}

    /** @param list<ObservationClaim> $claims @param list<ArbitrationDecision> $decisions */
    public function writeArbitration(array $claims, array $decisions, int $documentId, int $pageNumber): void
    {
        if ($claims === [] || $decisions === []) {
            throw new InvalidArgumentException('Arbitration projection cannot be empty.');
        }
        $byId = [];
        foreach ($claims as $claim) {
            $byId[$claim->id] = $claim;
        }
        $scope = $claims[0];
        $this->evidence->transaction($scope->organizationId, $scope->sessionId, function () use ($byId, $decisions, $documentId, $pageNumber, $scope): void {
            $entities = [];
            $facts = [];
            $domainEvidence = [];
            foreach ($decisions as $decision) {
                $claim = $byId[$decision->claimId] ?? throw new InvalidArgumentException('Arbitration claim is absent.');
                $this->assertScope($claim, $scope);
                $evidenceIds = [];
                foreach ($decision->supportingClaimIds as $supportingClaimId) {
                    $supportingClaim = $byId[$supportingClaimId]
                        ?? throw new InvalidArgumentException('Supporting arbitration claim is absent.');
                    $this->assertScope($supportingClaim, $scope);
                    if ($supportingClaim->evidenceRef === null
                        || ! in_array($supportingClaim->evidenceRef, $decision->evidenceRefs, true)) {
                        continue;
                    }
                    $hash = hash('sha256', $supportingClaim->id.'|'.$supportingClaim->sourceVersion.'|'.$supportingClaim->evidenceRef);
                    $node = $this->evidence->insertOrGet(new EvidenceData(
                        $scope->organizationId,
                        $scope->projectId,
                        $scope->sessionId,
                        EvidenceType::SourceFact,
                        EvidenceSourceType::Document,
                        'document:'.$documentId,
                        $scope->sourceVersion,
                        $this->evidenceLocator($supportingClaim, $documentId, $pageNumber, $hash),
                        [
                            'fact_key' => $this->evidenceFactKey($supportingClaim),
                            'fact_value' => $this->evidenceScalar($supportingClaim),
                        ],
                        $decision->status === 'accepted' && $supportingClaim->explicitEvidence ? 1.0 : 0.0,
                        EvidenceProducer::DrawingAnalyzer->value,
                        'sha256:'.hash('sha256', 'document-arbitration:v3'),
                    ));
                    $evidenceId = 'evidence:'.$node->id;
                    $evidenceIds[] = $evidenceId;
                    $domainEvidence[$evidenceId] = $this->domainEvidence($node);
                }
                $entityId = 'entity:'.hash('sha256', $claim->entityKey);
                $entities[$entityId] = new Entity(
                    $entityId,
                    $scope->organizationId,
                    $scope->projectId,
                    $scope->sessionId,
                    $scope->sourceVersion,
                    $this->entityType($claim),
                    $entityId,
                    ['source_key' => $claim->entityKey],
                );
                $factId = 'fact:'.hash('sha256', $claim->id.'|'.$decision->status.'|'.$scope->sourceVersion);
                $facts[$factId] = new Fact(
                    $factId,
                    $scope->organizationId,
                    $scope->projectId,
                    $scope->sessionId,
                    $scope->sourceVersion,
                    $entityId,
                    mb_substr($claim->factType, 0, 120),
                    $claim->value['data'],
                    $claim->unit,
                    $decision->status === 'accepted' ? 1.0 : 0.0,
                    $decision->status === 'unresolved' ? 'unresolved' : 'document',
                    match ($decision->status) {
                        'accepted' => 'confirmed',
                        'candidate' => 'candidate',
                        'unresolved' => 'unresolved',
                    },
                    $evidenceIds,
                );
            }
            $this->models->saveSourceModel(array_values($entities), array_values($facts), array_values($domainEvidence));
        });
    }

    private function evidenceScalar(ObservationClaim $claim): string|int|float|bool
    {
        $value = $claim->value['data'];
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return 'material:'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function evidenceLocator(ObservationClaim $claim, int $documentId, int $pageNumber, string $hash): array
    {
        $locator = [
            'document_id' => $documentId,
            'page' => $pageNumber,
            'element_key' => 'element:'.$hash,
        ];
        foreach (['unit_type', 'unit_index', 'sheet', 'region_key', 'bbox', 'source_key'] as $field) {
            if (array_key_exists($field, $claim->locator)) {
                $locator[$field] = $claim->locator[$field];
            }
        }

        return $locator;
    }

    private function evidenceFactKey(ObservationClaim $claim): string
    {
        $type = mb_strtolower($claim->factType);
        $native = EvidenceAttribute::tryFrom($type);
        if ($native !== null) {
            return $native->value;
        }

        return match (true) {
            str_contains($type, 'material'), str_contains($type, 'finish') => EvidenceAttribute::MaterialCode->value,
            str_contains($type, 'room') && str_contains($type, 'area') => EvidenceAttribute::RoomArea->value,
            str_contains($type, 'area') => EvidenceAttribute::Area->value,
            str_contains($type, 'perimeter') => EvidenceAttribute::Perimeter->value,
            str_contains($type, 'width') => EvidenceAttribute::OpeningWidth->value,
            str_contains($type, 'height') => EvidenceAttribute::WallHeight->value,
            str_contains($type, 'count'), str_contains($type, 'quantity') => EvidenceAttribute::Quantity->value,
            default => EvidenceAttribute::ElementTypeCode->value,
        };
    }

    private function assertScope(ObservationClaim $claim, ObservationClaim $scope): void
    {
        if ($claim->organizationId !== $scope->organizationId || $claim->projectId !== $scope->projectId
            || $claim->sessionId !== $scope->sessionId || $claim->sourceVersion !== $scope->sourceVersion) {
            throw new InvalidArgumentException('Arbitration claims do not share an exact scope.');
        }
    }

    private function entityType(ObservationClaim $claim): string
    {
        $haystack = mb_strtolower($claim->entityKey.' '.$claim->factType);

        return match (true) {
            str_contains($haystack, 'room') || str_contains($haystack, 'помещ') => 'room',
            str_contains($haystack, 'wall') || str_contains($haystack, 'стен') => 'wall',
            str_contains($haystack, 'opening') || str_contains($haystack, 'проем') || str_contains($haystack, 'проём') => 'opening',
            str_contains($haystack, 'equipment') || str_contains($haystack, 'оборуд') => 'equipment',
            str_contains($haystack, 'dimension') || str_contains($haystack, 'размер') => 'dimension',
            str_contains($haystack, 'quantity') || str_contains($haystack, 'колич') => 'quantity',
            default => 'material',
        };
    }

    private function domainEvidence(EvidenceNode $node): Evidence
    {
        $page = $node->locator['page'] ?? $node->locator['unit_index'] ?? null;
        $page = is_int($page) && $page > 0 ? $page : null;
        $region = null;
        $bbox = $node->locator['bbox'] ?? null;
        if (is_array($bbox) && count($bbox) === 4 && array_filter($bbox, 'is_numeric') === $bbox) {
            [$left, $top, $right, $bottom] = array_map('floatval', $bbox);
            if ($left >= 0 && $top >= 0 && $right > $left && $bottom > $top) {
                $region = ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top];
            }
        }

        return new Evidence(
            'evidence:'.$node->id,
            $node->organizationId,
            $node->projectId,
            $node->sessionId,
            $node->sourceVersion,
            $node->sourceRef,
            $node->sourceType->value,
            $page,
            $region,
            'evidence-node:'.$node->id,
        );
    }
}
