<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evaluation;

use InvalidArgumentException;

final readonly class EvaluationExample
{
    public function __construct(
        public string $sourceVersion,
        public array $expectedFacts,
        public array $expectedDecisions,
        public array $expectedQuantities,
        public array $expectedEstimateRows,
        public array $contractVersions,
        public EvaluationExampleTrust $trustStatus,
        public string $split,
    ) {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/', $sourceVersion) !== 1) {
            throw new InvalidArgumentException('Evaluation source version is invalid.');
        }
        if (! in_array($split, ['development', 'test'], true)) {
            throw new InvalidArgumentException('Evaluation split is invalid.');
        }
        foreach ($contractVersions as $contract => $version) {
            if (! is_string($contract) || $contract === '' || ! is_string($version) || $version === '') {
                throw new InvalidArgumentException('Evaluation contract version is invalid.');
            }
        }
    }

    public function withTrust(EvaluationExampleTrust $trust): self
    {
        return new self(
            $this->sourceVersion,
            $this->expectedFacts,
            $this->expectedDecisions,
            $this->expectedQuantities,
            $this->expectedEstimateRows,
            $this->contractVersions,
            $trust,
            $this->split,
        );
    }

    public function fingerprint(): string
    {
        return 'sha256:'.hash('sha256', json_encode([
            $this->sourceVersion,
            $this->expectedFacts,
            $this->expectedDecisions,
            $this->expectedQuantities,
            $this->expectedEstimateRows,
            $this->contractVersions,
            $this->split,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
