<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNoAirWorkItemPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationState;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;

final readonly class TargetedPackageRebuilder
{
    public function __construct(
        private ResourceAssemblyService $resources,
        private EstimatePricingService $pricing,
        private TargetedPackageDraftPatcher $patcher = new TargetedPackageDraftPatcher,
        private AssembleMatchedResources $matchedResources = new AssembleMatchedResources,
    ) {}

    public function rebuild(TargetedPackageRebuildCommand $command): TargetedPackagePatchResult
    {
        $this->assertDraftFence($command);
        $candidate = $this->targetCandidate($command);
        $this->assertTargetExistsExactlyOnce($command, $candidate);
        $this->assertCandidateIsEvidenceBounded($candidate);

        $regionalContext = $this->regionalContext($command->draft);
        $supplementaryMaterials = $this->supplementaryMaterials($command->draft);
        $context = new PipelineContext(
            $command->sessionId,
            $command->organizationId,
            $command->projectId,
            $command->expectedStateVersion,
            $command->sourceInputVersion,
            $command->sessionStatus,
            generationAttemptId: $command->operationId,
            baseInputVersion: $command->sourceInputVersion,
        );

        foreach ($candidate['sections'] as $sectionIndex => $section) {
            $workItems = $section['work_items'];
            $enriched = $this->resources->enrich($workItems, $regionalContext);
            $this->assertUnchangedWorkItemTopology($workItems, $enriched);

            $matched = $this->matchedResources->handle([
                'local_estimates' => [[...$candidate, 'sections' => [[...$section, 'work_items' => $enriched]]]],
                'supplementary_materials' => $supplementaryMaterials,
            ]);
            $matchedWorkItems = $this->matchedWorkItems($matched);
            $this->assertUnchangedWorkItemTopology($workItems, $matchedWorkItems);

            $priced = $this->pricing->price($matchedWorkItems, $regionalContext, $context);
            $this->assertUnchangedWorkItemTopology($workItems, $priced);
            $candidate['sections'][$sectionIndex]['work_items'] = $priced;
        }

        return $this->patcher->replace(
            $command->draft,
            $command->sourceInputVersion,
            $command->packageKey,
            $candidate,
        );
    }

    private function assertDraftFence(TargetedPackageRebuildCommand $command): void
    {
        $sourceInputVersion = $command->draft['source_input_version'] ?? null;
        if (! is_string($sourceInputVersion)
            || ! $this->isSha256($sourceInputVersion)
            || ! hash_equals($sourceInputVersion, $command->sourceInputVersion)) {
            $this->evidenceRequired();
        }

        $review = $command->draft['arbiter_review'] ?? null;
        $cycle = is_array($review) ? $review['cycle'] ?? null : null;
        $cycleInputHash = is_array($cycle) ? $cycle['input_hash'] ?? null : null;
        if (! is_string($cycleInputHash)
            || ! $this->isSha256($cycleInputHash)
            || ! hash_equals($cycleInputHash, $command->arbiterInputHash)) {
            $this->evidenceRequired();
        }

        $remediation = is_array($review) ? $review['remediation'] ?? null : null;
        if (! is_array($remediation)) {
            $this->evidenceRequired();
        }
        try {
            $state = ArbiterRemediationState::fromArray($remediation);
        } catch (\Throwable) {
            $this->evidenceRequired();
        }
        if (! hash_equals($state->rootInputHash, $command->arbiterInputHash)
            || $state->phase !== 'attempted'
            || ! $state->rebuildAttempted
            || ! in_array($command->packageKey, $state->targetPackageKeys, true)) {
            $this->evidenceRequired();
        }

        if ($command->verdict->outcome !== 'targeted_rebuild' || ! $this->verdictTargetsPackageWithEvidence($command)) {
            $this->evidenceRequired();
        }
    }

    /** @return array<string, mixed> */
    private function targetCandidate(TargetedPackageRebuildCommand $command): array
    {
        $localEstimates = $command->draft['local_estimates'] ?? null;
        if (! is_array($localEstimates) || ! array_is_list($localEstimates)) {
            $this->evidenceRequired();
        }
        foreach ($localEstimates as $localEstimate) {
            if (is_array($localEstimate) && ($localEstimate['key'] ?? null) === $command->packageKey) {
                return $localEstimate;
            }
        }

        $this->evidenceRequired();
    }

    /** @param array<string, mixed> $candidate */
    private function assertTargetExistsExactlyOnce(TargetedPackageRebuildCommand $command, array $candidate): void
    {
        try {
            $this->patcher->replace(
                $command->draft,
                $command->sourceInputVersion,
                $command->packageKey,
                $candidate,
            );
        } catch (\Throwable) {
            $this->evidenceRequired();
        }
    }

    /** @param array<string, mixed> $candidate */
    private function assertCandidateIsEvidenceBounded(array $candidate): void
    {
        $sections = $candidate['sections'] ?? null;
        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            $this->evidenceRequired();
        }

        $genericWorkItemPolicy = new EstimateGenerationNoAirWorkItemPolicy;
        foreach ($sections as $section) {
            $workItems = is_array($section) ? $section['work_items'] ?? null : null;
            if (! is_array($workItems) || ! array_is_list($workItems) || $workItems === []) {
                $this->evidenceRequired();
            }
            foreach ($workItems as $workItem) {
                if (! is_array($workItem) || ! $this->isPricedWorkItem($workItem)) {
                    continue;
                }
                if ($genericWorkItemPolicy->requiresReview($workItem) || ! $this->hasEvidenceBoundedQuantity($workItem)) {
                    $this->evidenceRequired();
                }
            }
        }
    }

    private function verdictTargetsPackageWithEvidence(TargetedPackageRebuildCommand $command): bool
    {
        foreach ($command->verdict->findings as $finding) {
            if (! is_array($finding) || ($finding['action'] ?? null) !== 'rebuild') {
                continue;
            }
            $packageKeys = $this->validReferences($finding['package_keys'] ?? null);
            $evidenceReferences = $this->validReferences($finding['evidence_refs'] ?? null);
            if ($packageKeys !== null
                && $evidenceReferences !== null
                && in_array($command->packageKey, $packageKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $workItem */
    private function isPricedWorkItem(array $workItem): bool
    {
        return ! in_array((string) ($workItem['item_type'] ?? 'priced_work'), [
            'operation',
            'resource_note',
            'review_note',
            'quantity_review',
        ], true);
    }

    /** @param array<string, mixed> $workItem */
    private function hasEvidenceBoundedQuantity(array $workItem): bool
    {
        $quantityEvidence = $workItem['quantity_evidence'] ?? null;
        if (! is_array($quantityEvidence)) {
            return false;
        }
        $evidenceIds = $quantityEvidence['evidence_ids'] ?? null;
        $reviewBlockers = $quantityEvidence['review_blockers'] ?? null;

        return $this->validReferences($evidenceIds) !== null
            && is_array($reviewBlockers)
            && $reviewBlockers === [];
    }

    /** @return list<string>|null */
    private function validReferences(mixed $references): ?array
    {
        if (! is_array($references) || ! array_is_list($references) || $references === []) {
            return null;
        }
        $normalized = [];
        foreach ($references as $reference) {
            if (is_int($reference) && $reference > 0) {
                $normalized[(string) $reference] = true;

                continue;
            }
            if (! is_string($reference) || preg_match('/\A[A-Za-z0-9:._-]{1,120}\z/', $reference) !== 1) {
                return null;
            }
            $normalized[$reference] = true;
        }

        return array_keys($normalized);
    }

    /** @return array<string, mixed> */
    private function regionalContext(array $draft): array
    {
        $regionalContext = $draft['regional_context'] ?? null;
        if (! is_array($regionalContext)) {
            $this->evidenceRequired();
        }

        return $regionalContext;
    }

    /** @return list<mixed> */
    private function supplementaryMaterials(array $draft): array
    {
        $supplementaryMaterials = $draft['supplementary_materials'] ?? [];
        if (! is_array($supplementaryMaterials) || ! array_is_list($supplementaryMaterials)) {
            $this->evidenceRequired();
        }

        return $supplementaryMaterials;
    }

    /** @param array<string, mixed> $matched @return list<array<string, mixed>> */
    private function matchedWorkItems(array $matched): array
    {
        $workItems = $matched['data']['local_estimates'][0]['sections'][0]['work_items'] ?? null;
        if (! is_array($workItems) || ! array_is_list($workItems)) {
            $this->evidenceRequired();
        }
        foreach ($workItems as $workItem) {
            if (! is_array($workItem)) {
                $this->evidenceRequired();
            }
        }

        return $workItems;
    }

    /** @param list<array<string, mixed>> $before @param list<array<string, mixed>> $after */
    private function assertUnchangedWorkItemTopology(array $before, array $after): void
    {
        if (! array_is_list($after) || count($before) !== count($after)) {
            $this->evidenceRequired();
        }
        foreach ($before as $index => $workItem) {
            $candidate = $after[$index] ?? null;
            if (! is_array($workItem)
                || ! is_array($candidate)
                || ! is_string($workItem['key'] ?? null)
                || ! is_string($candidate['key'] ?? null)
                || ! hash_equals($workItem['key'], $candidate['key'])) {
                $this->evidenceRequired();
            }
        }
    }

    private function isSha256(string $value): bool
    {
        return preg_match('/\Asha256:[a-f0-9]{64}\z/', $value) === 1;
    }

    private function evidenceRequired(): never
    {
        throw new TargetedPackageEvidenceRequired('Targeted package rebuild requires confirmed evidence.');
    }
}
