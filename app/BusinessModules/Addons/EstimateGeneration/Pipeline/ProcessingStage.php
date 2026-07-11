<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

enum ProcessingStage: string
{
    case UnderstandDocuments = 'understand_documents';
    case UnderstandObject = 'understand_object';
    case ExtractQuantities = 'extract_quantities';
    case PlanWorkItems = 'plan_work_items';
    case MatchNormatives = 'match_normatives';
    case AssembleResources = 'assemble_resources';
    case ResolvePrices = 'resolve_prices';
    case BuildDraft = 'build_draft';
    case ValidateDraft = 'validate_draft';

    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }

    public function next(): ?self
    {
        return self::cases()[$this->order() + 1] ?? null;
    }
}
