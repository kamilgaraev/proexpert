<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class TechnologyRecommendation
{
    public ?TechnologySystemOption $recommended;

    public function __construct(
        public string $decisionKey,
        public string $targetFactId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $catalogVersion,
        public string $catalogHash,
        public array $options,
        public array $responseOptions,
        public string $question,
        public bool $conditional,
        public array $missingFacts,
        public bool $autoApply = false,
    ) {
        $recommended = array_values(array_filter($options, static fn (TechnologySystemOption $option): bool => $option->recommended));
        if (count($recommended) > 1) {
            throw new \InvalidArgumentException('Technology recommendation has multiple recommended options.');
        }
        $this->recommended = $recommended[0] ?? null;
    }

    public function recommendedOption(): ?TechnologySystemOption
    {
        return $this->recommended;
    }

    public function toArray(): array
    {
        return [
            'decision_key' => $this->decisionKey,
            'target_fact_id' => $this->targetFactId,
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'session_id' => $this->sessionId,
            'source_version' => $this->sourceVersion,
            'catalog_version' => $this->catalogVersion,
            'catalog_hash' => $this->catalogHash,
            'options' => array_map(static fn (TechnologySystemOption $option): array => $option->toArray(), $this->options),
            'response_options' => $this->responseOptions,
            'question' => $this->question,
            'conditional' => $this->conditional,
            'missing_facts' => $this->missingFacts,
            'auto_apply' => $this->autoApply,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['decision_key'],
            $data['target_fact_id'],
            $data['organization_id'],
            $data['project_id'],
            $data['session_id'],
            $data['source_version'],
            $data['catalog_version'],
            $data['catalog_hash'],
            array_map(static fn (array $option): TechnologySystemOption => TechnologySystemOption::fromArray($option), $data['options']),
            $data['response_options'],
            $data['question'],
            $data['conditional'],
            $data['missing_facts'],
            $data['auto_apply'],
        );
    }
}
