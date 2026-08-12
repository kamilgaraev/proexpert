<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Review;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use RuntimeException;

final class ListEstimateReviewExceptions
{
    private const DEFAULT_TYPES = ['conflict', 'missing_required_data', 'low_confidence', 'technology_recommendation'];

    private const SOURCE_LIMIT = 1001;

    public function __construct(
        private readonly EstimateReviewExceptionSource $source,
        private readonly string $cursorSecret = 'estimate-review-exceptions-v1',
    ) {}

    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session, array $filters): array
    {
        $limit = max(1, min((int) ($filters['limit'] ?? 50), 100));
        $source = $this->source->current($session, self::SOURCE_LIMIT);
        $items = array_values(array_filter(array_map($this->sanitize(...), $source['items']), fn (array $item): bool => $this->matches($item, $filters)));
        usort($items, $this->compare(...));

        $cursor = $this->decodeCursor($filters['cursor'] ?? null, (int) $session->state_version);
        if ($cursor !== null) {
            $items = array_values(array_filter($items, fn (array $item): bool => $this->compareTuple($this->tuple($item), $cursor) > 0));
        }

        $summary = $this->summary($items);
        $pageItems = array_slice($items, 0, $limit);
        $hasMore = count($items) > $limit;
        $nextCursor = $hasMore && $pageItems !== []
            ? $this->encodeCursor((int) $session->state_version, $this->tuple($pageItems[array_key_last($pageItems)]))
            : null;

        return [
            'items' => $pageItems,
            'summary' => $summary,
            'meta' => [
                'limit' => $limit,
                'next_cursor' => $nextCursor,
                'partial' => (bool) $source['truncated'],
                'overflow' => (bool) $source['truncated'] || $hasMore,
                'canonical_sort' => true,
                'state_version' => (int) $session->state_version,
            ],
        ];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function sanitize(array $item): array
    {
        $cost = is_array($item['cost_impact'] ?? null) ? $item['cost_impact'] : [];
        $state = in_array($cost['state'] ?? null, ['known', 'unknown', 'not_applicable'], true) ? $cost['state'] : 'unknown';
        $amount = $state === 'known' && is_string($cost['amount'] ?? null) && preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/', $cost['amount']) === 1
            ? $cost['amount']
            : null;
        if ($state === 'known' && $amount === null) {
            $state = 'unknown';
        }

        return [
            'id' => mb_substr((string) ($item['id'] ?? ''), 0, 160),
            'type' => mb_substr((string) ($item['type'] ?? 'unknown'), 0, 80),
            'type_label' => mb_substr((string) ($item['type_label'] ?? ''), 0, 160),
            'title' => mb_substr((string) ($item['title'] ?? ''), 0, 500),
            'blocking' => (bool) ($item['blocking'] ?? false),
            'severity' => in_array($item['severity'] ?? null, ['blocking', 'warning', 'optional'], true) ? $item['severity'] : 'warning',
            'confidence' => $this->decimal($item['confidence'] ?? null, '0'),
            'cost_impact' => ['state' => $state, 'amount' => $amount, 'currency' => $state === 'known' ? 'RUB' : null],
            'floor' => $this->nullableBoundedString($item['floor'] ?? null, 80),
            'room' => $this->nullableBoundedString($item['room'] ?? null, 80),
            'section' => $this->nullableBoundedString($item['section'] ?? null, 160),
            'origin' => $this->nullableBoundedString($item['origin'] ?? null, 80),
            'unresolved_type' => $this->nullableBoundedString($item['unresolved_type'] ?? null, 80),
            'codes' => array_slice(array_values(array_unique(array_filter(array_map('strval', is_array($item['codes'] ?? null) ? $item['codes'] : [])))), 0, 32),
            'provenance' => $this->sanitizeProvenance($item['provenance'] ?? null),
            'locators' => array_slice(array_values(array_filter(array_map($this->sanitizeLocator(...), is_array($item['locators'] ?? null) ? $item['locators'] : []))), 0, 16),
            'recommendation' => $this->sanitizeRecommendation($item['recommendation'] ?? null),
        ];
    }

    /** @param array<string, mixed> $item */
    private function matches(array $item, array $filters): bool
    {
        $explicitType = is_string($filters['type'] ?? null) && $filters['type'] !== '';
        if (! $explicitType && ! in_array($item['type'], self::DEFAULT_TYPES, true)) {
            return false;
        }
        $equals = [
            'type' => 'type', 'severity' => 'severity', 'floor' => 'floor', 'room' => 'room',
            'section' => 'section', 'origin' => 'origin', 'unresolved_type' => 'unresolved_type',
        ];
        foreach ($equals as $filter => $field) {
            if (is_string($filters[$filter] ?? null) && $filters[$filter] !== '' && (string) $item[$field] !== $filters[$filter]) {
                return false;
            }
        }
        if (is_string($filters['cost_impact'] ?? null) && $filters['cost_impact'] !== ''
            && $item['cost_impact']['state'] !== $filters['cost_impact']) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compare(array $left, array $right): int
    {
        return $this->compareTuple($this->tuple($left), $this->tuple($right));
    }

    /** @param array<string, mixed> $item @return array<int, int|string> */
    private function tuple(array $item): array
    {
        $severity = ['blocking' => 0, 'warning' => 1, 'optional' => 2];
        $cost = $item['cost_impact']['state'] === 'known' ? $this->decimalSortKey((string) $item['cost_impact']['amount']) : str_repeat('0', 30);

        return [
            $item['blocking'] ? 0 : 1,
            $item['cost_impact']['state'] === 'known' ? 0 : 1,
            $cost,
            $severity[$item['severity']] ?? 9,
            $this->decimal($item['confidence'], '1'),
            (string) $item['id'],
        ];
    }

    /** @param array<int, int|string> $left @param array<int, int|string> $right */
    private function compareTuple(array $left, array $right): int
    {
        foreach ($left as $index => $value) {
            $comparison = $index === 2
                ? strcmp((string) ($right[$index] ?? ''), (string) $value)
                : ($value <=> ($right[$index] ?? null));
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /** @param array<int, array<string, mixed>> $items @return array<string, int|float|bool> */
    private function summary(array $items): array
    {
        $blocking = count(array_filter($items, static fn (array $item): bool => $item['blocking']));
        $known = count(array_filter($items, static fn (array $item): bool => $item['cost_impact']['state'] === 'known'));
        $unknown = count(array_filter($items, static fn (array $item): bool => $item['cost_impact']['state'] === 'unknown'));
        $located = count(array_filter($items, static fn (array $item): bool => $item['locators'] !== []));

        return [
            'unresolved' => count($items), 'blocking' => $blocking, 'nonblocking' => count($items) - $blocking,
            'known_cost_impact' => $known, 'unknown_cost_impact' => $unknown,
            'coverage' => count($items) === 0 ? 1.0 : round($located / count($items), 4),
            'review_alone_blocks_export' => false,
        ];
    }

    /** @param array<int, int|string> $tuple */
    private function encodeCursor(int $version, array $tuple): string
    {
        $payload = base64_encode(json_encode(['v' => $version, 'p' => $tuple], JSON_THROW_ON_ERROR));

        return $payload.'.'.hash_hmac('sha256', $payload, $this->cursorSecret);
    }

    /** @return array<int, int|string>|null */
    private function decodeCursor(mixed $cursor, int $version): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        if (! is_string($cursor) || ! str_contains($cursor, '.')) {
            throw new RuntimeException('estimate_generation.review_cursor_invalid');
        }
        [$payload, $signature] = explode('.', $cursor, 2);
        if (! hash_equals(hash_hmac('sha256', $payload, $this->cursorSecret), $signature)) {
            throw new RuntimeException('estimate_generation.review_cursor_invalid');
        }
        $decoded = json_decode((string) base64_decode($payload, true), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || (int) ($decoded['v'] ?? 0) !== $version || ! is_array($decoded['p'] ?? null)) {
            throw new RuntimeException('estimate_generation.review_cursor_stale');
        }

        return array_values($decoded['p']);
    }

    private function decimal(mixed $value, string $fallback): string
    {
        $value = is_string($value) || is_int($value) ? (string) $value : $fallback;

        return preg_match('/\A(?:0|1)(?:\.\d{1,6})?\z/', $value) === 1 ? $value : $fallback;
    }

    private function decimalSortKey(string $value): string
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');

        return (str_starts_with($value, '-') ? '0' : '1').str_pad($whole, 24, '0', STR_PAD_LEFT).str_pad($fraction, 4, '0');
    }

    private function nullableBoundedString(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /** @return array<string, string|int|null> */
    private function sanitizeProvenance(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $allowed = ['source_version', 'artifact_version', 'draft_version', 'catalog_version', 'price_version', 'stage', 'stable_key'];

        return array_intersect_key($value, array_flip($allowed));
    }

    /** @return array<string, mixed>|null */
    private function sanitizeLocator(mixed $value): ?array
    {
        if (! is_array($value) || ! is_int($value['artifact_id'] ?? null) || $value['artifact_id'] <= 0) {
            return null;
        }

        return [
            'artifact_id' => $value['artifact_id'],
            'source_version' => $this->nullableBoundedString($value['source_version'] ?? null, 160),
            'page' => is_int($value['page'] ?? null) && $value['page'] > 0 ? $value['page'] : null,
            'sheet' => $this->nullableBoundedString($value['sheet'] ?? null, 160),
            'region' => is_array($value['region'] ?? null) ? array_intersect_key($value['region'], array_flip(['x', 'y', 'width', 'height'])) : null,
            'native_reference' => $this->nullableBoundedString($value['native_reference'] ?? null, 255),
        ];
    }

    /** @return array<string, mixed>|null */
    private function sanitizeRecommendation(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $allowed = ['decision_key', 'question', 'rationale', 'applicability', 'evidence', 'alternatives', 'work_packages', 'response_options', 'selected_option'];

        return array_intersect_key($value, array_flip($allowed));
    }
}
