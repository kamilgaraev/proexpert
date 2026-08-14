<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

final readonly class EstimateClarificationAnswer
{
    /** @param array<string,mixed> $sourceLocator */
    public function __construct(
        public string $questionKey,
        public string $status,
        public string $response,
        public ?string $choiceLabel,
        public ?string $other,
        public string $decisionId,
        public array $sourceLocator,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return array_filter([
            'question_key' => $this->questionKey,
            'status' => $this->status,
            'response' => $this->response,
            'choice_label' => $this->choiceLabel,
            'other' => $this->other,
            'decision_id' => $this->decisionId,
            'source_locator' => $this->sourceLocator,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
