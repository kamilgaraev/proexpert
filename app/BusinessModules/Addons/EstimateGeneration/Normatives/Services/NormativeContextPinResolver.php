<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use InvalidArgumentException;

class NormativeContextPinResolver
{
    public function __construct(private readonly ?NormativeContextPinSource $source = null) {}

    public function resolve(array $regionalContext, array $workIntents = []): array
    {
        $date = $this->date($regionalContext);
        $values = [
            'dataset_id' => $regionalContext['normative_dataset_id'] ?? $regionalContext['dataset_id'] ?? null,
            'dataset_version' => $regionalContext['normative_dataset_version'] ?? null,
            'region_id' => $regionalContext['region_id'] ?? null,
            'price_zone_id' => $regionalContext['price_zone_id'] ?? null,
            'period_id' => $regionalContext['period_id'] ?? null,
            'regional_price_version_id' => $regionalContext['estimate_regional_price_version_id'] ?? null,
            'price_version' => $regionalContext['price_version'] ?? null,
        ];
        if ($date === null) {
            return ['status' => 'review_required', 'blocking_issues' => ['normative_applicability_date_not_pinned']];
        }
        if (! is_int($values['dataset_id']) || ! is_string($values['dataset_version'])
            || ! is_int($values['region_id']) || ! is_int($values['price_zone_id'])
            || ! is_int($values['period_id']) || ! is_int($values['regional_price_version_id'])
            || ! is_string($values['price_version'])) {
            return ['status' => 'review_required', 'blocking_issues' => ['normative_resource_context_not_pinned']];
        }
        try {
            $requested = new NormativeContextPinData(
                $values['dataset_id'], $values['dataset_version'], $date, $values['region_id'],
                $values['price_zone_id'], $values['period_id'], $values['regional_price_version_id'],
                $values['price_version'],
            );
        } catch (InvalidArgumentException) {
            return ['status' => 'review_required', 'blocking_issues' => ['normative_resource_context_not_pinned']];
        }
        $intents = $this->intents($workIntents);
        if ($intents === null) {
            return ['status' => 'review_required', 'blocking_issues' => ['normative_work_intents_limit_exceeded']];
        }
        if ($intents === []) {
            return ['status' => 'review_required', 'blocking_issues' => ['normative_work_intents_not_pinned']];
        }
        $approved = $this->source?->resolveForIntents($requested, $intents);
        if ($approved === null || $approved->catalogCandidates === [] || $approved->catalogContentHash === null) {
            return ['status' => 'review_required', 'blocking_issues' => ['normative_resource_context_not_approved']];
        }
        $identity = $approved->toArray();

        return [
            'status' => 'pinned',
            ...$identity,
            'regional_context' => [
                'dataset_id' => $approved->datasetId,
                'dataset_version' => $approved->datasetVersion,
                'region_id' => $approved->regionId,
                'price_zone_id' => $approved->priceZoneId,
                'period_id' => $approved->periodId,
                'price_version' => $approved->priceVersion,
                'estimate_regional_price_version_id' => $approved->regionalPriceVersionId,
            ],
            'identity_version' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
        ];
    }

    private function date(array $context): ?string
    {
        foreach (['applicability_date', 'estimate_date', 'business_date'] as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
                return $value;
            }
        }
        $year = $context['year'] ?? null;
        $quarter = $context['quarter'] ?? null;
        if (is_int($year) && is_int($quarter) && $year >= 2000 && $quarter >= 1 && $quarter <= 4) {
            return sprintf('%04d-%02d-01', $year, (($quarter - 1) * 3) + 1);
        }

        return null;
    }

    /** @return list<array{search_text: string, unit: string, code?: string|null}>|null */
    private function intents(array $workIntents): ?array
    {
        $resolved = [];
        foreach ($workIntents as $intent) {
            if (! is_array($intent)) {
                continue;
            }
            $search = trim((string) ($intent['search_text'] ?? ''));
            $unit = trim((string) ($intent['unit'] ?? ''));
            $code = isset($intent['code']) ? trim((string) $intent['code']) : null;
            if ($search === '' || mb_strlen($search) > 500 || $unit === '' || mb_strlen($unit) > 32
                || ($code !== null && mb_strlen($code) > 80)) {
                continue;
            }
            $key = mb_strtolower($search).'|'.mb_strtolower($unit).'|'.mb_strtolower((string) $code);
            $resolved[$key] = ['search_text' => $search, 'unit' => $unit, 'code' => $code];
            if (count($resolved) > 64) {
                return null;
            }
        }

        return array_values($resolved);
    }
}
