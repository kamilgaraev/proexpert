<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use InvalidArgumentException;

final readonly class TechnologySystem
{
    public function __construct(
        public string $id,
        public string $nameKey,
        public array $applicability,
        public array $requiredFacts,
        public array $materials,
        public array $works,
        public array $machinery,
        public array $normIntents,
        public array $quantityFormulas,
        public array $regionalPriceAvailability,
        public array $costPreview,
        public array $risks,
        public array $assumptions,
        public array $scoreRules,
        public array $provenance,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{2,127}$/D', $id) !== 1
            || preg_match('/^[a-z][a-z0-9._-]{2,191}$/D', $nameKey) !== 1) {
            throw new InvalidArgumentException('Technology system identity is invalid.');
        }
        foreach ([
            'applicability' => [$applicability, 1, 20],
            'required facts' => [$requiredFacts, 1, 20],
            'materials' => [$materials, 1, 40],
            'works' => [$works, 1, 40],
            'machinery' => [$machinery, 1, 20],
            'norm intents' => [$normIntents, 1, 20],
            'quantity formulas' => [$quantityFormulas, 1, 20],
            'risks' => [$risks, 1, 20],
            'assumptions' => [$assumptions, 1, 20],
            'score rules' => [$scoreRules, 1, 30],
            'provenance' => [$provenance, 1, 20],
        ] as $name => [$items, $minimum, $maximum]) {
            if (! array_is_list($items) || count($items) < $minimum || count($items) > $maximum) {
                throw new InvalidArgumentException('Technology system '.$name.' are invalid.');
            }
        }
        foreach ([$regionalPriceAvailability, $costPreview] as $availability) {
            if (array_is_list($availability) || ! is_bool($availability['available'] ?? null)) {
                throw new InvalidArgumentException('Technology system availability is invalid.');
            }
        }
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name_key' => $this->nameKey,
            'applicability' => $this->applicability,
            'required_facts' => $this->requiredFacts,
            'materials' => $this->materials,
            'works' => $this->works,
            'machinery' => $this->machinery,
            'norm_intents' => $this->normIntents,
            'quantity_formulas' => $this->quantityFormulas,
            'regional_price_availability' => $this->regionalPriceAvailability,
            'cost_preview' => $this->costPreview,
            'risks' => $this->risks,
            'assumptions' => $this->assumptions,
            'score_rules' => $this->scoreRules,
            'provenance' => $this->provenance,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['name_key'],
            $data['applicability'],
            $data['required_facts'],
            $data['materials'],
            $data['works'],
            $data['machinery'],
            $data['norm_intents'],
            $data['quantity_formulas'],
            $data['regional_price_availability'],
            $data['cost_preview'],
            $data['risks'],
            $data['assumptions'],
            $data['score_rules'],
            $data['provenance'],
        );
    }
}
