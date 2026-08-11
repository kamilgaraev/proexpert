<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

final readonly class TechnologySystemOption
{
    public function __construct(
        public TechnologySystem $system,
        public int $score,
        public array $scoreContributions,
        public bool $recommended,
        public string $label,
        public string $explanation,
    ) {}

    public function toArray(): array
    {
        return [
            'system' => $this->system->toArray(),
            'score' => $this->score,
            'score_contributions' => $this->scoreContributions,
            'recommended' => $this->recommended,
            'label' => $this->label,
            'explanation' => $this->explanation,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            TechnologySystem::fromArray($data['system']),
            $data['score'],
            $data['score_contributions'],
            $data['recommended'],
            $data['label'],
            $data['explanation'],
        );
    }
}
