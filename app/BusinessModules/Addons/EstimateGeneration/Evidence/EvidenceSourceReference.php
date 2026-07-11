<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

use InvalidArgumentException;

final readonly class EvidenceSourceReference
{
    public function __construct(public EvidenceSourceType $type, public string $value)
    {
        $pattern = match ($type) {
            EvidenceSourceType::Document, EvidenceSourceType::DocumentUnit => '/^document:[1-9][0-9]*$/D',
            EvidenceSourceType::PageRegion => '/^document:[1-9][0-9]*\/page:[1-9][0-9]*\/region:(?:[1-9][0-9]*|[a-f0-9]{64})$/D',
            EvidenceSourceType::UserInput => '/^input:(?:[1-9][0-9]*|[a-f0-9-]{36})$/D',
            EvidenceSourceType::CatalogNorm => '/^norm:(?:(?:gesn|fer):[0-9]+(?:-[0-9]+){1,5}|fsnb:[0-9]{4}-[1-9][0-9]*)$/D',
            EvidenceSourceType::PriceSnapshot => '/^price:(?:fgiscs:[0-9]{4}-(?:0[1-9]|1[0-2])|regional:(?:[1-9][0-9]*|[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}))$/D',
            EvidenceSourceType::Pipeline => '/^pipeline:(?:understand_object|infer_scope|quantity_takeoff|decompose|match_normatives|price|validate|persist)$/D',
        };
        if (preg_match($pattern, $value) !== 1 || strlen($value) > 160) {
            throw new InvalidArgumentException('Evidence source reference is invalid.');
        }
    }
}
