<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;
use DateTimeImmutable;

final class NormativeWorkIntentFactory
{
    public function __construct(
        private ?WorkIntentClassifier $classifier = null,
        private ?NormativeRerankerModelSet $modelSet = null,
        private ?ResidentialMaterialScenarioCatalog $materialScenarioCatalog = null,
    ) {}

    public function intent(array $item, array $context, string $datasetVersion): WorkIntentData
    {
        $recorded = is_array($item['work_intent'] ?? null) ? $item['work_intent'] : [];
        $this->classifier ??= app(WorkIntentClassifier::class);
        $intent = $this->classifier->classify($item, $context);
        $classified = [
            'material' => $intent->material, 'action' => $intent->action, 'scope' => $intent->scope,
            'object' => $intent->object, 'expected_dimensions' => $intent->expectedDimensions,
            'preferred_section_prefixes' => $intent->preferredSectionPrefixes,
        ];
        foreach ($recorded as $key => $value) {
            if ((is_string($value) && trim($value) !== '') || (is_array($value) && $value !== [])) {
                if ($this->hasStrongClassifiedValue($key, $classified[$key] ?? null)) {
                    continue;
                }
                $classified[$key] = $value;
            }
        }
        $evidence = $this->evidence($context['source_refs'] ?? []);
        $objectType = ObjectTypeSignalClassifier::canonical((string) ($context['object_type'] ?? ''));
        $quantityKey = $this->quantityKey($item);
        $specializationEvidence = $this->specializationEvidence(
            $recorded['specialization_evidence'] ?? $item['specialization_evidence'] ?? [],
            $evidence,
        );
        $specializationScenario = $this->specializationScenario(
            $item['specialization_scenario'] ?? $recorded['specialization_scenario'] ?? null,
            $quantityKey,
            $objectType,
        );
        if (is_string($specializationScenario['intent_action'] ?? null)
            && trim($specializationScenario['intent_action']) !== '') {
            $classified['action'] = trim($specializationScenario['intent_action']);
        }
        $rateCode = trim((string) ($specializationScenario['normative_rate_code'] ?? ''));
        if (preg_match('/^(\d{2})-\d{2}-\d{3}-\d{2}$/D', $rateCode, $matches) === 1) {
            $classified['preferred_section_prefixes'] = [$matches[1]];
        }
        $requestedNormativeCode = $rateCode !== ''
            ? $rateCode
            : (is_string($item['normative_rate_code'] ?? null) && $item['normative_rate_code'] !== ''
                ? $item['normative_rate_code']
                : null);

        $preferredSections = array_values(array_filter(
            $classified['preferred_section_prefixes'] ?? [],
            static fn (mixed $section): bool => is_string($section) && $section !== '',
        ));

        return new WorkIntentData(
            (int) $context['organization_id'], (int) $context['project_id'], (int) $context['session_id'],
            (string) ($item['key'] ?? $item['id'] ?? hash('sha256', json_encode($item, JSON_THROW_ON_ERROR))),
            (string) ($item['normative_search_text'] ?? $item['name'] ?? ''), (string) ($item['unit'] ?? ''),
            (string) ($classified['expected_dimensions'][0] ?? ''), (string) ($classified['material'] ?? ''),
            (string) ($classified['action'] ?? ''), (string) ($classified['scope'] ?? ''),
            count($preferredSections) === 1 ? $preferredSections[0] : '',
            $objectType,
            $datasetVersion, 'parsed', $this->region($context), new DateTimeImmutable((string) $context['applicability_date']),
            $evidence, $preferredSections,
            $requestedNormativeCode,
            (string) ($intent->system ?? ''),
            (string) ($classified['object'] ?? ''),
            $specializationEvidence,
            $specializationScenario,
        );
    }

    public function decision(array $item, array $context): NormativeCandidateDecisionContextData
    {
        return new NormativeCandidateDecisionContextData(
            (int) $context['organization_id'], (int) $context['project_id'], (int) $context['session_id'],
            (string) ($item['key'] ?? $item['id'] ?? hash('sha256', json_encode($item, JSON_THROW_ON_ERROR))),
            (string) $context['checkpoint_claim_token'], (string) $context['input_version'], (int) $context['logical_attempt'],
            'normative-rerank-prompt-v1', 'normative-rerank-v1', ($this->modelSet ?? new NormativeRerankerModelSet)->version(),
            $this->evidence($context['source_refs'] ?? []),
        );
    }

    private function region(array $context): ?string
    {
        $regional = is_array($context['regional_context'] ?? null) ? $context['regional_context'] : [];
        $value = $regional['region_code'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hasStrongClassifiedValue(string $key, mixed $value): bool
    {
        if (! in_array($key, ['action', 'scope', 'object', 'expected_dimensions', 'preferred_section_prefixes'], true)) {
            return false;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return is_string($value) && $value !== '' && ! in_array($value, ['general', 'general_work'], true);
    }

    private function evidence(mixed $evidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        $references = [];
        foreach ($evidence as $reference) {
            $normalized = $this->evidenceReference($reference);
            if ($normalized !== null) {
                $references[] = $normalized;
            }
        }

        return array_slice(array_values(array_unique($references)), 0, 32);
    }

    private function evidenceReference(mixed $reference): ?string
    {
        if (is_string($reference) || is_int($reference)) {
            $value = trim((string) $reference);

            return $value !== '' && strlen($value) <= 128 ? $value : null;
        }

        if (! is_array($reference)) {
            return null;
        }

        foreach (['evidence_id', 'source_evidence_id', 'id'] as $key) {
            if (is_string($reference[$key] ?? null) || is_int($reference[$key] ?? null)) {
                return $this->evidenceReference($reference[$key]);
            }
        }

        ksort($reference);
        $encoded = json_encode($reference, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return null;
        }

        $value = 'source-ref:'.$encoded;

        return strlen($value) <= 128 ? $value : 'source-ref-sha256:'.hash('sha256', $encoded);
    }

    /**
     * @param  list<string>  $sourceEvidence
     * @return list<array<string, mixed>>
     */
    private function specializationEvidence(mixed $evidence, array $sourceEvidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        $result = [];
        foreach ($evidence as $item) {
            if (! is_array($item)
                || ! in_array($item['source'] ?? null, ['document', 'building_model', 'user_confirmation'], true)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            $refs = is_array($item['evidence_refs'] ?? null)
                ? array_values(array_unique(array_filter(
                    $item['evidence_refs'],
                    static fn (mixed $ref): bool => is_string($ref) && in_array($ref, $sourceEvidence, true),
                )))
                : [];
            if ($text === '' || $refs === []) {
                continue;
            }

            $result[] = [
                'text' => mb_substr($text, 0, 2000),
                'source' => $item['source'],
                'evidence_refs' => $refs,
            ];
        }

        return array_slice($result, 0, 32);
    }

    /** @return array<string, mixed>|null */
    private function specializationScenario(mixed $scenario, string $quantityKey, string $objectType): ?array
    {
        $this->materialScenarioCatalog ??= new ResidentialMaterialScenarioCatalog;

        return $this->materialScenarioCatalog->resolve($scenario, $quantityKey, $objectType);
    }

    /** @param array<string, mixed> $item */
    private function quantityKey(array $item): string
    {
        $metadata = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];

        foreach ([$metadata['material_scenario_work_key'] ?? null, $metadata['quantity_key'] ?? null, $item['quantity_formula'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
