<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use DateTimeImmutable;

final class NormativeWorkIntentFactory
{
    public function __construct(private ?WorkIntentClassifier $classifier = null) {}

    public function intent(array $item, array $context, string $datasetVersion): WorkIntentData
    {
        $classified = is_array($item['work_intent'] ?? null) ? $item['work_intent'] : [];
        if ($classified === []) {
            $this->classifier ??= app(WorkIntentClassifier::class);
            $intent = $this->classifier->classify($item, $context);
            $classified = [
                'material' => $intent->material, 'action' => $intent->action, 'scope' => $intent->scope,
                'object' => $intent->object, 'expected_dimensions' => $intent->expectedDimensions,
                'preferred_section_prefixes' => $intent->preferredSectionPrefixes,
            ];
        }
        $evidence = $this->evidence($context['source_refs'] ?? []);

        return new WorkIntentData(
            (int) $context['organization_id'], (int) $context['project_id'], (int) $context['session_id'],
            (string) ($item['key'] ?? $item['id'] ?? hash('sha256', json_encode($item, JSON_THROW_ON_ERROR))),
            (string) ($item['normative_search_text'] ?? $item['name'] ?? ''), (string) ($item['unit'] ?? ''),
            (string) ($classified['expected_dimensions'][0] ?? ''), (string) ($classified['material'] ?? ''),
            (string) ($classified['action'] ?? ''), (string) ($classified['scope'] ?? ''),
            (string) ($classified['preferred_section_prefixes'][0] ?? ''), (string) ($classified['object'] ?? ''),
            $datasetVersion, 'parsed', $this->region($context), new DateTimeImmutable((string) $context['applicability_date']),
            $evidence,
        );
    }

    public function decision(array $item, array $context): NormativeCandidateDecisionContextData
    {
        return new NormativeCandidateDecisionContextData(
            (int) $context['organization_id'], (int) $context['project_id'], (int) $context['session_id'],
            (string) ($item['key'] ?? $item['id'] ?? hash('sha256', json_encode($item, JSON_THROW_ON_ERROR))),
            (string) $context['checkpoint_claim_token'], (string) $context['input_version'], (int) $context['logical_attempt'],
            'normative-rerank-prompt-v1', 'normative-rerank-v1', (string) config('estimate-generation.normative_matching.reranker.models.0', 'configured'),
            $this->evidence($context['source_refs'] ?? []),
        );
    }

    private function region(array $context): ?string
    {
        $regional = is_array($context['regional_context'] ?? null) ? $context['regional_context'] : [];
        $value = $regional['region_code'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function evidence(mixed $evidence): array
    {
        if (! is_array($evidence)) {
            return [];
        }

        return array_slice(array_values(array_unique(array_filter($evidence, static fn (mixed $ref): bool => is_string($ref) && $ref !== '' && strlen($ref) <= 128))), 0, 32);
    }
}
