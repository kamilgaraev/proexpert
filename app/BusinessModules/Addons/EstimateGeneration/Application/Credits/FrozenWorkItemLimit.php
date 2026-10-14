<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** A scoped, frozen ceiling. No mutable counter is shared between operations. */
final class FrozenWorkItemLimit
{
    public static function forOperation(int $organizationId, int $projectId, int $sessionId, ?string $operationId): ?int
    {
        if ($operationId === null) {
            return null;
        }
        $stored = DB::table('estimate_generation_credit_operations')->where('organization_id', $organizationId)
            ->where('project_id', $projectId)->where('session_id', $sessionId)->where('operation_id', $operationId)
            ->value('processing_plan');
        if (! is_string($stored)) {
            throw new InvalidArgumentException('estimate_work_approved_plan_missing');
        }

        return self::fromPlan(json_decode($stored, true, 64, JSON_THROW_ON_ERROR));
    }

    public static function fromPlan(array $plan): ?int
    {
        $version = $plan['contract_version'] ?? 'estimator-processing-plan:v1';
        if ($version === 'estimator-processing-plan:v1') {
            return null; // Historical quotes keep their original part/call limits and reserve.
        }
        $limit = $plan['work_item_limit'] ?? null;
        if ($version !== 'estimator-processing-plan:v2' || ($plan['work_ceiling_policy'] ?? null) !== 'global_work_items:v1'
            || ! is_int($limit) || $limit < 0 || $limit > 512
            || (isset($plan['roles']['estimate_audit']) && (! is_int($plan['audit_part_limit'] ?? null)
                || $plan['audit_part_limit'] < max(1, $limit)))) {
            throw new InvalidArgumentException('estimate_work_approved_plan_invalid');
        }

        return $limit;
    }

    public static function assertCount(int $count, ?int $limit): void
    {
        if ($count < 0 || ($limit !== null && $count > $limit)) {
            throw new InvalidArgumentException('estimate_work_items_exceed_approved_plan');
        }
    }

    public static function assertDraft(array $draft, ?int $limit): void
    {
        self::assertCount(count(self::items($draft)), $limit);
    }

    /** Count the candidate part inside the current full draft, including earlier parts' additions. */
    public static function assertReplacement(array $full, array $replacement, array $originalKeys, ?int $limit): void
    {
        $keys = array_fill_keys($originalKeys, true);
        $unaffected = array_filter(self::items($full), static fn (array $item): bool => ! isset($keys[$item['key'] ?? '']));
        self::assertCount(count($unaffected) + count(self::items($replacement)), $limit);
    }

    public static function assertCorrections(array $draft, array $corrections, ?int $limit): void
    {
        $additions = array_filter($corrections, static fn (array $correction): bool => ($correction['operation'] ?? null) === 'add_work');
        self::assertCount(count(self::items($draft)) + count($additions), $limit);
    }

    private static function items(array $draft): array
    {
        $items = [];
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                array_push($items, ...($section['work_items'] ?? []));
            }
        }

        return $items;
    }
}
