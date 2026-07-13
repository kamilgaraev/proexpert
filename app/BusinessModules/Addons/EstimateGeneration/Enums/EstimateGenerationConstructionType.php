<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Enums;

use function trans_message;

enum EstimateGenerationConstructionType: string
{
    case NewConstruction = 'new_construction';
    case Reconstruction = 'reconstruction';
    case CapitalRepair = 'capital_repair';
    case CurrentRepair = 'current_repair';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    public function label(): string
    {
        return trans_message('estimate_generation.construction_type_'.$this->value);
    }
}
