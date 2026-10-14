<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Audit;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use InvalidArgumentException;

/** Derived presentation snapshots are rebuilt after audit; source business data stays intact. */
final class EstimateAuditDraftView
{
    public const LEGACY = 'legacy:v1';

    public const BUSINESS = 'business:v1';

    public static function project(array $draft, string $policy): array
    {
        if (! in_array($policy, [self::LEGACY, self::BUSINESS], true)) {
            throw new InvalidArgumentException('estimate_audit_draft_view_invalid');
        }
        if ($policy === self::BUSINESS && ($draft['generation_contract'] ?? null) === UniversalDraftProjector::CONTRACT) {
            unset($draft['evaluation_result'], $draft['quality_summary']);
        }

        return $draft;
    }
}
