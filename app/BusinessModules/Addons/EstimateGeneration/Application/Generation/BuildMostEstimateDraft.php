<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Generation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Facades\Facade;
use JsonException;

final class BuildMostEstimateDraft
{
    private const CONTRACT = 'most_ordinary_estimate:v1';

    private const MAX_CANDIDATE_ROWS = 1000;

    private const MAX_REVIEW_ITEMS = 1000;

    private const DEFAULT_LIMITS = [
        'max_rows' => 5000,
        'max_metadata_bytes_per_row' => 32768,
        'max_source_refs_per_row' => 16,
    ];

    public function __construct(private readonly ?Closure $translate = null) {}

    /** @param array<string, mixed> $draft @return array<string, mixed> */
    public function build(array $draft): array
    {
        $limits = $this->limits($draft['stage6_limits'] ?? null);
        $reviewItems = [];
        foreach (array_slice(is_array($draft['stage6_review_items'] ?? null) ? $draft['stage6_review_items'] : [], 0, self::MAX_REVIEW_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $code = is_string($item['code'] ?? null) ? $item['code'] : 'canonical_quantity_unresolved';
            $messageKey = is_string($item['message_key'] ?? null)
                ? $item['message_key'] : 'estimate_generation.geometry_coverage_review';
            $reviewItems[] = [
                'type' => 'quantity_blocking',
                'code' => $code,
                'work_item_key' => null,
                'message' => $this->message($messageKey),
                'entity_id' => is_string($item['entity_id'] ?? null) ? $item['entity_id'] : null,
                'source_refs' => array_slice(is_array($item['source_refs'] ?? null) ? $item['source_refs'] : [], 0, 16),
            ];
        }
        $candidateRows = [];
        $processedRows = 0;
        foreach (['catalog_identity', 'technology_identity', 'rule_identity'] as $identityKey) {
            if (($draft[$identityKey]['status'] ?? null) !== 'current') {
                $reviewItems[] = $this->reviewItem('stage5_blocking', 'generation_identity_not_current');
            }
        }

        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            if (! is_array($localEstimate)) {
                continue;
            }

            foreach ($localEstimate['sections'] ?? [] as $sectionIndex => $section) {
                if (! is_array($section)) {
                    continue;
                }

                $rows = [];
                foreach ($section['work_items'] ?? [] as $workItem) {
                    if ($processedRows >= $limits['max_rows']) {
                        $reviewItems[] = $this->reviewItem('draft_budget', 'draft_row_budget_exceeded');
                        if (is_array($workItem) && count($candidateRows) < self::MAX_CANDIDATE_ROWS) {
                            $candidateRows[] = $this->budgetCandidate($workItem);
                        }

                        break;
                    }
                    if (! is_array($workItem)) {
                        continue;
                    }

                    [$row, $rowReviewItems] = $this->processRow($workItem, $draft, $limits);
                    if ($rowReviewItems === []) {
                        $rows[] = $row;
                    } elseif (count($candidateRows) < self::MAX_CANDIDATE_ROWS) {
                        $candidateRows[] = $row;
                    }
                    array_push($reviewItems, ...$rowReviewItems);
                    $processedRows++;
                }

                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = $rows;
            }
        }

        $draft['generation_contract'] = self::CONTRACT;
        $draft['stage6_candidate_rows'] = $candidateRows;
        $draft['stage6_review_items'] = $this->uniqueReviewItems($reviewItems);
        $draft['is_complete'] = $draft['stage6_review_items'] === [];
        $draft['stage6_status'] = $draft['is_complete'] ? 'ready' : 'review_required';
        unset($draft['artifact_hash']);
        $draft['artifact_hash'] = hash('sha256', $this->canonicalJson($draft));
        $this->attachArtifactHash($draft, $draft['artifact_hash']);

        return $draft;
    }

    /** @param array<string, mixed> $draft */
    public function verifyArtifact(array $draft): bool
    {
        $expected = $draft['artifact_hash'] ?? null;
        if (! is_string($expected) || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1) {
            return false;
        }
        unset($draft['artifact_hash']);
        $this->attachArtifactHash($draft, null);

        return hash_equals($expected, hash('sha256', $this->canonicalJson($draft)));
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @param  array<string, mixed>  $draft
     * @param  array{max_rows: int, max_metadata_bytes_per_row: int, max_source_refs_per_row: int}  $limits
     * @return array{array<string, mixed>, list<array<string, mixed>>}
     */
    private function processRow(array $workItem, array $draft, array $limits): array
    {
        $reviewItems = [];
        $quantity = $workItem['quantity_evidence'] ?? null;
        $norm = $workItem['normative_match'] ?? null;
        $price = $workItem['price_snapshot'] ?? null;
        $unit = $this->string($workItem['unit'] ?? null);

        if (! $this->quantityIsReady($quantity, $workItem, $draft)) {
            $reviewItems[] = $this->reviewItem('quantity_blocking', 'quantity_not_proven', $workItem);
        }
        if (! $this->normIsReady($norm, $workItem, $draft)) {
            $reviewItems[] = $this->reviewItem('normative_blocking', 'normative_not_compatible', $workItem);
        }
        if (! $this->priceIsReady($price, $workItem, $draft)) {
            $reviewItems[] = $this->reviewItem('price_blocking', 'regional_price_not_compatible', $workItem);
        }
        if (! $this->storageDecimalsAreReady($workItem, $price)) {
            $reviewItems[] = $this->reviewItem('draft_storage', 'ordinary_estimate_decimal_out_of_range', $workItem);
        }

        $provenance = $this->provenance($workItem, $draft, $limits['max_source_refs_per_row']);
        if (strlen($this->canonicalJson($provenance)) > $limits['max_metadata_bytes_per_row']) {
            $reviewItems[] = $this->reviewItem('draft_budget', 'draft_metadata_budget_exceeded', $workItem);
            $provenance['source_refs'] = [];
            $provenance['quantity']['operands'] = [];
        }

        $workItem['metadata'] = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];
        $workItem['metadata']['stage6_provenance'] = $provenance;
        unset($workItem['prompt'], $workItem['raw_document'], $workItem['document_content']);
        if ($reviewItems !== []) {
            $workItem['pricing_status'] = 'not_calculated';
            $workItem['pricing_blocker'] = $reviewItems[0]['code'];
            $workItem['total_cost'] = '0';
        } else {
            $workItem['pricing_status'] = 'calculated';
            $workItem['pricing_blocker'] = null;
            $workItem['total_cost'] = BigDecimal::of((string) $price['final_amount'])->strippedOfTrailingZeros()->__toString();
        }

        return [$workItem, $reviewItems];
    }

    /** @param array<string, mixed> $workItem @return array<string, mixed> */
    private function budgetCandidate(array $workItem): array
    {
        unset($workItem['prompt'], $workItem['raw_document'], $workItem['document_content']);
        $workItem['pricing_status'] = 'not_calculated';
        $workItem['pricing_blocker'] = 'draft_row_budget_exceeded';
        $workItem['total_cost'] = '0';

        return $workItem;
    }

    /** @param mixed $quantity @param array<string, mixed> $workItem @param array<string, mixed> $draft */
    private function quantityIsReady(mixed $quantity, array $workItem, array $draft): bool
    {
        if (! is_array($quantity) || ($quantity['review_blockers'] ?? []) !== []
            || ! $this->generationIdentitiesAreCurrent($draft)) {
            return false;
        }

        $amount = $this->decimal($quantity['amount'] ?? null);
        $workAmount = $this->decimal($workItem['quantity'] ?? null);
        $unit = $this->string($quantity['unit'] ?? null);
        $workUnit = $this->string($workItem['unit'] ?? null);
        $evidenceIds = $quantity['evidence_ids'] ?? [];
        $snapshot = $quantity['snapshot_identity']['input_fingerprint']
            ?? $quantity['formula_inputs']['snapshot_identity']['input_fingerprint']
            ?? null;
        $operands = $quantity['formula_inputs']['operands'] ?? null;
        $scope = $draft['scope_identity'] ?? null;

        return $amount !== null
            && $workAmount !== null
            && $amount->isGreaterThan(BigDecimal::zero())
            && $amount->compareTo($workAmount) === 0
            && $unit !== null
            && $unit === $workUnit
            && is_array($evidenceIds)
            && $evidenceIds !== []
            && is_string($snapshot)
            && hash_equals((string) ($draft['input_snapshot_hash'] ?? ''), $snapshot)
            && is_array($scope)
            && is_array($operands)
            && $operands !== []
            && $this->operandsMatchScope($operands, $scope)
            && is_string($quantity['formula_identity'] ?? $quantity['formula_key'] ?? null)
            && is_string($quantity['formula_version'] ?? null);
    }

    /** @param array<string, mixed> $draft */
    private function generationIdentitiesAreCurrent(array $draft): bool
    {
        foreach (['catalog_identity', 'technology_identity', 'rule_identity'] as $identity) {
            if (($draft[$identity]['status'] ?? null) !== 'current') {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $operands @param array<string, mixed> $scope */
    private function operandsMatchScope(array $operands, array $scope): bool
    {
        foreach ($operands as $operand) {
            if (! is_array($operand)) {
                return false;
            }
            foreach (['organization_id', 'project_id', 'session_id', 'source_version'] as $key) {
                if (! array_key_exists($key, $scope) || ! array_key_exists($key, $operand)
                    || $operand[$key] !== $scope[$key]) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param mixed $norm @param array<string, mixed> $workItem @param array<string, mixed> $draft */
    private function normIsReady(mixed $norm, array $workItem, array $draft): bool
    {
        $retrieval = $workItem['normative_retrieval'] ?? null;
        $catalog = $draft['catalog_identity'] ?? null;

        return is_array($norm)
            && ($norm['status'] ?? null) === 'matched'
            && ($norm['hard_gate_passed'] ?? false) === true
            && $this->string($norm['unit'] ?? null) === $this->string($workItem['unit'] ?? null)
            && is_array($retrieval)
            && in_array($retrieval['status'] ?? null, ['matched', 'retrieval_only', 'reranked'], true)
            && ($retrieval['blocking_issues'] ?? []) === []
            && is_array($catalog)
            && ($catalog['status'] ?? null) === 'current'
            && $this->normVersion($norm['dataset_version'] ?? null) === ($catalog['version'] ?? null);
    }

    /** @param mixed $price @param array<string, mixed> $workItem @param array<string, mixed> $draft */
    private function priceIsReady(mixed $price, array $workItem, array $draft): bool
    {
        $region = $draft['regional_context'] ?? null;
        if (! is_array($price) || ! is_array($region)) {
            return false;
        }

        $finalAmount = $this->decimal($price['final_amount'] ?? null);
        $unit = $this->string($workItem['unit'] ?? null);

        return $finalAmount !== null
            && $finalAmount->isGreaterThan(BigDecimal::zero())
            && $unit !== null
            && ($workItem['pricing_status'] ?? null) === 'calculated'
            && ($workItem['pricing_blocker'] ?? null) === null
            && ($price['region_id'] ?? null) === ($region['region_id'] ?? null)
            && ($price['zone_id'] ?? null) === ($region['price_zone_id'] ?? null)
            && ($price['period_id'] ?? null) === ($region['period_id'] ?? null)
            && ($price['version_id'] ?? null) === ($region['estimate_regional_price_version_id'] ?? null)
            && is_string($price['source_reference'] ?? null)
            && is_string($price['captured_at'] ?? null)
            && ($workItem['pricing_finalized_at'] ?? null) === $price['captured_at'];
    }

    /** @param mixed $price @param array<string, mixed> $workItem */
    private function storageDecimalsAreReady(array $workItem, mixed $price): bool
    {
        if (! OrdinaryEstimateDecimal::fitsQuantity($workItem['quantity'] ?? null)
            || ! is_array($price)
            || ! OrdinaryEstimateDecimal::fitsMoney($price['final_amount'] ?? null)) {
            return false;
        }
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                if (! is_array($resource)
                    || ! OrdinaryEstimateDecimal::fitsResourceQuantity($resource['quantity'] ?? null)
                    || ! OrdinaryEstimateDecimal::fitsResourceQuantity($resource['quantity_per_unit'] ?? 1)
                    || ! OrdinaryEstimateDecimal::fitsUnitPrice($resource['unit_price'] ?? null)
                    || ! OrdinaryEstimateDecimal::fitsMoney($resource['unit_price'] ?? null)
                    || ! OrdinaryEstimateDecimal::fitsMoney($resource['total_price'] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $workItem @param array<string, mixed> $draft @return array<string, mixed> */
    private function provenance(array $workItem, array $draft, int $maxSourceRefs): array
    {
        $quantity = is_array($workItem['quantity_evidence'] ?? null) ? $workItem['quantity_evidence'] : [];
        $norm = is_array($workItem['normative_match'] ?? null) ? $workItem['normative_match'] : [];
        $price = is_array($workItem['price_snapshot'] ?? null) ? $workItem['price_snapshot'] : [];
        $metadata = is_array($workItem['metadata'] ?? null) ? $workItem['metadata'] : [];

        return [
            'quantity' => [
                'amount' => $quantity['amount'] ?? null,
                'unit' => $quantity['unit'] ?? null,
                'formula_identity' => $quantity['formula_identity'] ?? ($quantity['formula_key'] ?? null),
                'formula_version' => $quantity['formula_version'] ?? null,
                'rounding_policy' => $quantity['rounding_policy']
                    ?? ($quantity['formula_inputs']['rounding_policy'] ?? null),
                'operands' => array_slice($quantity['formula_inputs']['operands'] ?? [], 0, 64),
                'evidence_ids' => array_slice($quantity['evidence_ids'] ?? [], 0, $maxSourceRefs),
                'model_version' => $quantity['model_version'] ?? null,
                'snapshot_identity' => $quantity['snapshot_identity']
                    ?? ($quantity['formula_inputs']['snapshot_identity'] ?? null),
            ],
            'source_refs' => array_slice(is_array($workItem['source_refs'] ?? null) ? $workItem['source_refs'] : [], 0, $maxSourceRefs),
            'norm' => [
                'id' => $norm['norm_id'] ?? null,
                'code' => $norm['code'] ?? null,
                'unit' => $norm['unit'] ?? null,
                'dataset_version' => $norm['dataset_version'] ?? null,
                'hard_gate_passed' => $norm['hard_gate_passed'] ?? false,
            ],
            'price' => [
                'source_type' => $price['source_type'] ?? null,
                'source_reference' => $price['source_reference'] ?? null,
                'source_version' => isset($price['version_id']) ? 'regional-version:'.$price['version_id'] : null,
                'source_date' => $price['captured_at'] ?? null,
                'region_id' => $price['region_id'] ?? null,
                'zone_id' => $price['zone_id'] ?? null,
                'period_id' => $price['period_id'] ?? null,
                'version_id' => $price['version_id'] ?? null,
                'unit' => $workItem['unit'] ?? null,
                'base_amount' => $price['base_amount'] ?? null,
                'coefficients' => $price['coefficients'] ?? [],
                'final_amount' => $price['final_amount'] ?? null,
                'currency' => $price['currency'] ?? null,
            ],
            'technology_decision' => $this->decision($metadata['technology_decision'] ?? null),
            'completeness_decision' => $this->decision($metadata['completeness_decision'] ?? null),
            'artifact' => [
                'input_snapshot_hash' => $draft['input_snapshot_hash'] ?? null,
                'source_input_version' => $draft['source_input_version'] ?? null,
                'catalog_identity' => $draft['catalog_identity'] ?? null,
                'technology_identity' => $draft['technology_identity'] ?? null,
                'rule_identity' => $draft['rule_identity'] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function decision(mixed $decision): ?array
    {
        if (! is_array($decision)) {
            return null;
        }

        return array_intersect_key($decision, array_flip(['id', 'version', 'status', 'actor_id', 'decided_at', 'provenance']));
    }

    /** @param array<string, mixed> $workItem @return array<string, mixed> */
    private function reviewItem(string $type, string $code, array $workItem = []): array
    {
        $key = 'estimate_generation.stage6.'.$code;

        return [
            'type' => $type,
            'code' => $code,
            'work_item_key' => $workItem['key'] ?? null,
            'message' => $this->message($key),
        ];
    }

    private function message(string $key): string
    {
        if ($this->translate !== null) {
            return ($this->translate)($key);
        }

        try {
            return Facade::getFacadeApplication() !== null ? trans_message($key) : $key;
        } catch (\Throwable) {
            return $key;
        }
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function uniqueReviewItems(array $items): array
    {
        $unique = [];
        $exceeded = false;
        foreach ($items as $item) {
            $key = $item['type'].'|'.$item['code'].'|'.($item['work_item_key'] ?? '');
            if (isset($unique[$key])) {
                continue;
            }
            if (count($unique) >= self::MAX_REVIEW_ITEMS - 1) {
                $exceeded = true;
                break;
            }
            $unique[$key] = $item;
        }
        if ($exceeded) {
            $item = $this->reviewItem('draft_budget', 'stage6_review_budget_exceeded');
            $unique[$item['type'].'|'.$item['code'].'|'] = $item;
        }

        return array_values($unique);
    }

    /** @return array{max_rows: int, max_metadata_bytes_per_row: int, max_source_refs_per_row: int} */
    private function limits(mixed $limits): array
    {
        $limits = is_array($limits) ? $limits : [];

        return [
            'max_rows' => $this->boundedInt($limits['max_rows'] ?? null, self::DEFAULT_LIMITS['max_rows'], 1, 10000),
            'max_metadata_bytes_per_row' => $this->boundedInt($limits['max_metadata_bytes_per_row'] ?? null, self::DEFAULT_LIMITS['max_metadata_bytes_per_row'], 1024, 65536),
            'max_source_refs_per_row' => $this->boundedInt($limits['max_source_refs_per_row'] ?? null, self::DEFAULT_LIMITS['max_source_refs_per_row'], 1, 64),
        ];
    }

    private function boundedInt(mixed $value, int $default, int $min, int $max): int
    {
        return is_int($value) && $value >= $min && $value <= $max ? $value : $default;
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        try {
            return BigDecimal::of((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function normVersion(mixed $version): ?string
    {
        if (is_string($version)) {
            return $version;
        }

        return is_array($version) && is_string($version['version_key'] ?? null)
            ? $version['version_key']
            : null;
    }

    /** @param array<string, mixed> $draft */
    private function attachArtifactHash(array &$draft, ?string $artifactHash): void
    {
        foreach ($draft['local_estimates'] ?? [] as $localIndex => $localEstimate) {
            foreach (is_array($localEstimate) ? ($localEstimate['sections'] ?? []) : [] as $sectionIndex => $section) {
                foreach (is_array($section) ? ($section['work_items'] ?? []) : [] as $rowIndex => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if ($artifactHash === null) {
                        unset($draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$rowIndex]['metadata']['stage6_provenance']['artifact']['artifact_hash']);
                    } else {
                        $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][$rowIndex]['metadata']['stage6_provenance']['artifact']['artifact_hash'] = $artifactHash;
                    }
                }
            }
        }
    }

    /** @throws JsonException */
    private function canonicalJson(array $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
