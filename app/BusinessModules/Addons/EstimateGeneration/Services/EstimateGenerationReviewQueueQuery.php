<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\ReviewSummarySnapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EstimateGenerationReviewQueueQuery
{
    /** @return array{summary: array<string, int>, items: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function paginate(EstimateGenerationSession $session, array $filters): array
    {
        if (! $this->hasFreshProjection($session)) {
            throw new RuntimeException('estimate_generation.review_projection_stale');
        }

        $perPage = max(min((int) ($filters['per_page'] ?? 20), 100), 1);
        $requestedPage = max((int) ($filters['page'] ?? 1), 1);
        [$where, $bindings] = $this->filters($session, $filters);
        $summaryRow = DB::selectOne($this->summarySql($where), $bindings);
        $summary = $this->summary((array) $summaryRow);
        $lastPage = max((int) ceil($summary['total'] / $perPage), 1);
        $page = min($requestedPage, $lastPage);
        $pageBindings = [...$bindings, $perPage, ($page - 1) * $perPage];
        $rows = DB::select($this->pageSql($where), $pageBindings);

        return [
            'summary' => $summary,
            'items' => array_map(fn (object $row): array => $this->decodeItem($row), $rows),
            'meta' => ['total' => $summary['total'], 'current_page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage],
        ];
    }

    public function hasFreshProjection(EstimateGenerationSession $session): bool
    {
        $draft = is_array($session->draft_payload) ? $session->draft_payload : [];
        $projection = data_get($draft, 'quality_summary.review_queue_items');
        $snapshot = data_get($draft, 'quality_summary.review_items');

        return is_array($projection)
            && is_array($snapshot)
            && ReviewSummarySnapshot::isFresh($draft, $snapshot);
    }

    public function baseSql(): string
    {
        return <<<'SQL'
WITH session_projection AS (
    SELECT review_item
    FROM estimate_generation_sessions AS sessions
    CROSS JOIN LATERAL jsonb_array_elements(
        COALESCE(sessions.draft_payload #> '{quality_summary,review_queue_items}', '[]'::jsonb)
    ) AS projection(review_item)
    WHERE sessions.id = ? AND sessions.organization_id = ?
), latest_package_revisions AS (
    SELECT DISTINCT ON (items.package_id, COALESCE(items.logical_key, items.key))
        items.id, items.package_id, packages.key AS package_key, COALESCE(items.logical_key, items.key) AS logical_key
    FROM estimate_generation_package_items AS items
    INNER JOIN estimate_generation_packages AS packages ON packages.id = items.package_id
    WHERE packages.session_id = ?
    ORDER BY items.package_id, COALESCE(items.logical_key, items.key), items.revision DESC, items.id DESC
), active_projection AS (
    SELECT projection.review_item
    FROM session_projection AS projection
    WHERE NOT EXISTS (SELECT 1 FROM latest_package_revisions)
       OR EXISTS (
            SELECT 1
            FROM latest_package_revisions AS latest
            WHERE latest.package_key = projection.review_item->>'local_estimate_key'
              AND latest.logical_key = projection.review_item->>'work_item_key'
       )
), filtered AS (
    SELECT review_item
    FROM active_projection
    WHERE %s
)
SQL;
    }

    public function summarySql(string $where = 'TRUE'): string
    {
        return sprintf($this->baseSql(), $where).<<<'SQL'
SELECT
    COUNT(*) AS total,
    COUNT(*) FILTER (WHERE review_item->>'severity' = 'blocking') AS blocking,
    COUNT(*) FILTER (WHERE review_item->>'severity' = 'warning') AS warning,
    COUNT(*) FILTER (WHERE review_item->>'severity' = 'optional') AS optional,
    COUNT(*) FILTER (WHERE review_item->>'required_action' = 'confirm_quantity') AS confirm_quantity,
    COUNT(*) FILTER (WHERE review_item->>'required_action' = 'select_norm') AS select_norm,
    COUNT(*) FILTER (WHERE review_item->>'required_action' = 'review_norm') AS review_norm,
    COUNT(*) FILTER (WHERE review_item->>'required_action' = 'resolve_duplicate') AS resolve_duplicate,
    COUNT(*) FILTER (WHERE review_item->>'required_action' = 'resolve_generic_work') AS resolve_generic_work,
    COUNT(*) FILTER (WHERE review_item->>'required_action' = 'check_price') AS check_price
FROM filtered
SQL;
    }

    public function pageSql(string $where = 'TRUE'): string
    {
        return sprintf($this->baseSql(), $where).<<<'SQL'
SELECT review_item
FROM filtered
ORDER BY
    CASE review_item->>'severity' WHEN 'blocking' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END,
    review_item->>'local_estimate_title',
    review_item->>'section_title',
    review_item #>> '{work_item,name}',
    review_item->>'key'
LIMIT ? OFFSET ?
SQL;
    }

    /** @return array{0: string, 1: array<int, int|string>} */
    private function filters(EstimateGenerationSession $session, array $filters): array
    {
        $clauses = ['TRUE'];
        $bindings = [(int) $session->getKey(), (int) $session->organization_id, (int) $session->getKey()];

        if (is_string($filters['severity'] ?? null) && $filters['severity'] !== '') {
            $clauses[] = "review_item->>'severity' = ?";
            $bindings[] = $filters['severity'];
        }
        if (is_string($filters['required_action'] ?? null) && $filters['required_action'] !== '') {
            $clauses[] = "review_item->>'required_action' = ?";
            $bindings[] = $filters['required_action'];
        }
        if (is_string($filters['search'] ?? null) && trim($filters['search']) !== '') {
            $clauses[] = "concat_ws(' ', review_item->>'local_estimate_title', review_item->>'section_title', review_item->>'work_item_key', review_item #>> '{work_item,name}') ILIKE ? ESCAPE '\\'";
            $bindings[] = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /** @return array<string, int> */
    private function summary(array $row): array
    {
        $keys = ['total', 'blocking', 'warning', 'optional', 'confirm_quantity', 'select_norm', 'review_norm', 'resolve_duplicate', 'resolve_generic_work', 'check_price'];

        return array_combine($keys, array_map(static fn (string $key): int => (int) ($row[$key] ?? 0), $keys));
    }

    /** @return array<string, mixed> */
    private function decodeItem(object $row): array
    {
        $value = $row->review_item ?? null;
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new RuntimeException('estimate_generation.review_projection_invalid');
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('estimate_generation.review_projection_invalid');
        }

        return $decoded;
    }
}
