<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Questions;

use InvalidArgumentException;

final readonly class EstimateClarificationChoice
{
    public function __construct(
        public string $value,
        public string $label,
        public string $kind = 'option',
    ) {
        if (trim($value) === '' || mb_strlen($value) > 160 || trim($label) === '' || mb_strlen($label) > 160
            || ! in_array($kind, ['option', 'other', 'leave_unresolved'], true)) {
            throw new InvalidArgumentException('estimate_clarification_choice_invalid');
        }
    }

    /** @return array{value:string,label:string,kind:string} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label, 'kind' => $this->kind];
    }
}
