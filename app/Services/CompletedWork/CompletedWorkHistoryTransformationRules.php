<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

final class CompletedWorkHistoryTransformationRules
{
    public const CATEGORY_MATCHING = 'matching';

    public const CATEGORY_MISSING_COMPLETED = 'missing_completed_quantity';

    public const CATEGORY_MISSING_QUANTITY = 'missing_quantity';

    public const CATEGORY_CONFLICT = 'quantity_conflict';

    public const CATEGORY_INVALID = 'invalid_quantity';

    public const CATEGORY_DUPLICATE = 'duplicate_journal_volume';

    public const CATEGORY_ALREADY_TRANSFORMED = 'already_transformed';

    public const ACTION_APPLY = 'apply';

    public const ACTION_RECORD_CANONICAL = 'record_canonical';

    public const ACTION_SKIP_MANUAL = 'skip_manual';

    public const ACTION_ALREADY_DONE = 'already_done';

    public const RULE_MATCHING = 'matching_already_canonical';

    public const RULE_FILL_COMPLETED = 'fill_completed_from_quantity';

    public const RULE_FILL_QUANTITY = 'fill_quantity_from_completed';

    public const RULE_MANUAL = 'manual_decision';

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    public const OUTCOME_APPLIED = 'applied';

    public const OUTCOME_RECORDED = 'recorded';

    public const OUTCOME_ALREADY_CANONICAL = 'already_canonical';

    public const AUTO_OPERATION_KEY = 't27-auto';

    public static function plan(array $report, ?array $transformation): array
    {
        if ($transformation !== null) {
            $storedCanonical = $transformation['canonical_quantity'] ?? null;

            return [
                'category' => self::CATEGORY_ALREADY_TRANSFORMED,
                'auto_action' => self::ACTION_ALREADY_DONE,
                'rule' => $transformation['rule'] ?? null,
                'canonical_quantity' => is_string($storedCanonical) || is_int($storedCanonical)
                    ? CompletedWorkReconciliationService::normalizeQuantity($storedCanonical)
                    : null,
                'mutate' => false,
            ];
        }

        $issues = $report['issues'] ?? [];
        $protected = (bool) ($report['protected_history'] ?? false);
        $quantity = CompletedWorkReconciliationService::normalizeQuantity($report['source']['quantity'] ?? null);
        $completed = CompletedWorkReconciliationService::normalizeQuantity($report['source']['completed_quantity'] ?? null);
        $rawQuantity = $report['source']['quantity'] ?? null;
        $missingQuantity = in_array('invalid_quantity', $issues, true)
            && $rawQuantity === null
            && $completed !== null;

        if (in_array('duplicate_journal_volume', $issues, true)) {
            return self::manual(self::CATEGORY_DUPLICATE);
        }
        if (in_array('quantity_conflict', $issues, true)) {
            return self::manual(self::CATEGORY_CONFLICT);
        }
        if (in_array('invalid_quantity', $issues, true) && ! $missingQuantity) {
            return self::manual(self::CATEGORY_INVALID);
        }
        if (in_array('missing_completed_quantity', $issues, true)) {
            if ($protected || $quantity === null) {
                return self::manual(self::CATEGORY_MISSING_COMPLETED);
            }

            return [
                'category' => self::CATEGORY_MISSING_COMPLETED,
                'auto_action' => self::ACTION_APPLY,
                'rule' => self::RULE_FILL_COMPLETED,
                'canonical_quantity' => $quantity,
                'mutate' => true,
            ];
        }
        if ($missingQuantity) {
            if ($protected) {
                return self::manual(self::CATEGORY_MISSING_QUANTITY);
            }

            return [
                'category' => self::CATEGORY_MISSING_QUANTITY,
                'auto_action' => self::ACTION_APPLY,
                'rule' => self::RULE_FILL_QUANTITY,
                'canonical_quantity' => $completed,
                'mutate' => true,
            ];
        }
        if ($issues === [] && $quantity !== null && $quantity === $completed) {
            return [
                'category' => self::CATEGORY_MATCHING,
                'auto_action' => self::ACTION_RECORD_CANONICAL,
                'rule' => self::RULE_MATCHING,
                'canonical_quantity' => $completed,
                'mutate' => false,
            ];
        }

        return self::manual(self::CATEGORY_INVALID);
    }

    public static function canonicalFromDecision(array $report, string $source, mixed $explicit): ?string
    {
        return match ($source) {
            'quantity' => CompletedWorkReconciliationService::normalizeQuantity($report['source']['quantity'] ?? null),
            'completed_quantity' => CompletedWorkReconciliationService::normalizeQuantity($report['source']['completed_quantity'] ?? null),
            'explicit' => CompletedWorkReconciliationService::normalizeQuantity($explicit),
            default => null,
        };
    }

    private static function manual(string $category): array
    {
        return [
            'category' => $category,
            'auto_action' => self::ACTION_SKIP_MANUAL,
            'rule' => self::RULE_MANUAL,
            'canonical_quantity' => null,
            'mutate' => false,
        ];
    }
}
