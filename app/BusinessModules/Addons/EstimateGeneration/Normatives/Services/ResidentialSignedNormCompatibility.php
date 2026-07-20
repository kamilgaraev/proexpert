<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectTypeSignalClassifier;

final readonly class ResidentialSignedNormCompatibility
{
    public function __construct(
        private ResidentialMaterialScenarioCatalog $scenarios = new ResidentialMaterialScenarioCatalog,
    ) {}

    /** @param array<string, mixed>|null $scenario */
    public function matches(?array $scenario, string $objectType, string $candidateCode, string $candidateTitle): bool
    {
        $workItemKey = is_array($scenario) ? trim((string) ($scenario['work_item_key'] ?? '')) : '';
        $canonicalObjectType = ObjectTypeSignalClassifier::canonical($objectType);
        $resolved = $workItemKey !== '' && $canonicalObjectType === 'residential'
            ? $this->scenarios->resolve($scenario, $workItemKey, $canonicalObjectType)
            : null;
        if (! is_array($resolved)
            || trim((string) ($resolved['normative_rate_code'] ?? '')) !== trim($candidateCode)) {
            return false;
        }

        $candidateTokens = array_fill_keys($this->tokens($candidateTitle), true);
        $matches = array_filter(
            $this->tokens((string) ($resolved['normative_search_text'] ?? '')),
            static fn (string $token): bool => isset($candidateTokens[$token]),
        );

        return count($matches) >= 2;
    }

    /** @return list<string> */
    private function tokens(string $text): array
    {
        $tokens = [];
        foreach (preg_split('/[^\pL\pN.-]+/u', mb_strtolower($text)) ?: [] as $token) {
            if (mb_strlen($token) >= 4 && ! NormativeLexemePolicy::isGeneric($token)) {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }
}
