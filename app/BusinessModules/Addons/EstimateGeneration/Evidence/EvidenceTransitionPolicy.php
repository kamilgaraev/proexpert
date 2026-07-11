<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Evidence;

final class EvidenceTransitionPolicy
{
    public static function allows(EvidenceType $parent, EvidenceRelation $relation, EvidenceType $child): bool
    {
        return in_array($child, self::targets($parent, $relation), true);
    }

    /** @return list<EvidenceType> */
    private static function targets(EvidenceType $parent, EvidenceRelation $relation): array
    {
        return match ([$parent, $relation]) {
            [EvidenceType::SourceFact, EvidenceRelation::DerivedFrom] => [EvidenceType::Extracted, EvidenceType::Measured],
            [EvidenceType::SourceFact, EvidenceRelation::Supports] => [EvidenceType::Extracted, EvidenceType::Measured, EvidenceType::Inferred],
            [EvidenceType::SourceFact, EvidenceRelation::Contradicts],
            [EvidenceType::SourceFact, EvidenceRelation::Resolves] => [EvidenceType::SourceFact],
            [EvidenceType::Extracted, EvidenceRelation::DerivedFrom] => [EvidenceType::Extracted, EvidenceType::Measured, EvidenceType::Inferred],
            [EvidenceType::Extracted, EvidenceRelation::Supports] => [EvidenceType::Measured, EvidenceType::Inferred, EvidenceType::WorkItem],
            [EvidenceType::Measured, EvidenceRelation::DerivedFrom],
            [EvidenceType::Measured, EvidenceRelation::Supports] => [EvidenceType::Measured, EvidenceType::Inferred, EvidenceType::WorkItem],
            [EvidenceType::Inferred, EvidenceRelation::DerivedFrom],
            [EvidenceType::Inferred, EvidenceRelation::Supports] => [EvidenceType::Inferred, EvidenceType::WorkItem],
            [EvidenceType::WorkItem, EvidenceRelation::MatchedTo] => [EvidenceType::NormativeMatch],
            [EvidenceType::NormativeMatch, EvidenceRelation::PricedBy] => [EvidenceType::Price],
            default => [],
        };
    }
}
