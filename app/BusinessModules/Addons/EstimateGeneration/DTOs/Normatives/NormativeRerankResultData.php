<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Normatives;

final readonly class NormativeRerankResultData
{
    /**
     * @param array<int, string> $evidenceKeys
     * @param array<int, string> $warnings
     */
    public function __construct(
        public ?string $selectedCandidateKey,
        public float $confidence,
        public string $reason,
        public array $evidenceKeys,
        public array $warnings,
        public string $provider,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'selected_candidate_key' => $this->selectedCandidateKey,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'evidence_keys' => $this->evidenceKeys,
            'warnings' => $this->warnings,
            'provider' => $this->provider,
        ];
    }

    /**
     * @param array<int, string> $warnings
     */
    public function withWarnings(array $warnings): self
    {
        return new self(
            selectedCandidateKey: $this->selectedCandidateKey,
            confidence: $this->confidence,
            reason: $this->reason,
            evidenceKeys: $this->evidenceKeys,
            warnings: array_values(array_unique([...$this->warnings, ...$warnings])),
            provider: $this->provider,
        );
    }
}
