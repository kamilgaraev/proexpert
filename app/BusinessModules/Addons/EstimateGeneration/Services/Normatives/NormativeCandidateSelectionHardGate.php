<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialSignedNormCompatibility;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final readonly class NormativeCandidateSelectionHardGate
{
    public function __construct(
        private WorkIntentClassifier $workIntentClassifier,
        private NormativeSearchProfileCatalog $searchProfileCatalog,
        private NormativeSemanticCompatibilityService $semanticCompatibilityService,
        private ResidentialSignedNormCompatibility $signedNormCompatibility = new ResidentialSignedNormCompatibility,
    ) {}

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $match
     * @return list<string>
     */
    public function rejectionReasons(array $workItem, array $context, array $match): array
    {
        $candidate = is_array($match['selected'] ?? null) ? $match['selected'] : [];
        if ($candidate === []) {
            return ['candidate_missing'];
        }

        $reasons = [];
        $workUnit = trim((string) ($workItem['unit'] ?? ''));
        $candidateUnit = trim((string) ($candidate['unit'] ?? ''));
        if ($workUnit === '' || $candidateUnit === '' || ! NormativeUnitNormalizer::compatible($workUnit, $candidateUnit)) {
            $reasons[] = 'unit_mismatch';
        }

        $intent = $this->workIntentClassifier->classify($workItem, $context);
        $profile = $this->searchProfileCatalog->forIntentData($intent);
        $objectType = ObjectTypeSignalClassifier::canonical((string) ($context['object_type'] ?? ''));
        $signedScenario = is_array($workItem['specialization_scenario'] ?? null)
            ? $workItem['specialization_scenario']
            : null;
        $signedScenarioMatches = $this->signedNormCompatibility->matches(
            $signedScenario,
            $objectType,
            (string) ($candidate['code'] ?? ''),
            (string) ($candidate['name'] ?? ''),
        );
        $section = is_array($candidate['section'] ?? null) ? $candidate['section'] : [];
        $sectionCode = trim((string) ($section['code'] ?? $candidate['section_code'] ?? ''));
        if (! $signedScenarioMatches && $profile->allowedSectionPrefixes !== []
            && ! $this->sectionStartsWithAny($sectionCode, $profile->allowedSectionPrefixes)) {
            $reasons[] = 'normative_section_mismatch';
        }
        if (! $signedScenarioMatches && $this->sectionStartsWithAny($sectionCode, [
            ...$intent->forbiddenSectionPrefixes,
            ...$profile->forbiddenSectionPrefixes,
        ])) {
            $reasons[] = 'normative_section_mismatch';
        }

        $candidateObjectType = ObjectTypeSignalClassifier::canonical((string) ($candidate['object_type'] ?? ''));
        if ($objectType !== '' && $candidateObjectType !== ''
            && ! ObjectTypeSignalClassifier::compatible($candidateObjectType, $objectType)) {
            $reasons[] = 'object_type_mismatch';
        }

        $candidateText = trim(implode(' ', [
            (string) ($candidate['name'] ?? ''),
            ...$this->strings($candidate['work_composition'] ?? []),
        ]));
        $workText = trim((string) ($workItem['normative_search_text'] ?? $workItem['name'] ?? ''));
        $semanticCompatible = $candidateText !== '' && $workText !== '' && $this->semanticCompatibilityService->isCompatible(
            $candidateText,
            $workText,
            [
                'scope' => $intent->scope,
                'action' => $intent->action,
                'system' => $intent->system,
                'object' => $intent->object,
                'object_type' => $objectType,
                'candidate_title' => (string) ($candidate['name'] ?? ''),
            ],
            $profile->forbiddenDomainTerms,
        );
        if (! $semanticCompatible && ! $signedScenarioMatches) {
            $reasons[] = 'semantic_mismatch';
        }

        return array_values(array_unique($reasons));
    }

    /** @param list<string> $prefixes */
    private function sectionStartsWithAny(string $sectionCode, array $prefixes): bool
    {
        if ($sectionCode === '') {
            return false;
        }

        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($sectionCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $item): bool => is_string($item) && trim($item) !== ''))
            : [];
    }
}
