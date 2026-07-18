<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

class NormativeContextPinResolver
{
    public function __construct(
        private readonly ?NormativeContextPinSource $source = null,
        private readonly ?ConnectionInterface $database = null,
    ) {}

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
            'price_version' => $regionalContext['price_version'] ?? $regionalContext['version_key'] ?? null,
        ];
        $values = $this->completeIdentity($values);
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

    private function completeIdentity(array $values): array
    {
        if ($this->database === null) {
            return $values;
        }
        if (! is_int($values['dataset_id']) && is_string($values['dataset_version']) && $values['dataset_version'] !== '') {
            $datasetId = $this->database->table('estimate_dataset_versions')
                ->where('source_type', 'fsnb_2022')->where('status', 'parsed')
                ->where('version_key', $values['dataset_version'])->value('id');
            $values['dataset_id'] = is_numeric($datasetId) ? (int) $datasetId : null;
        }
        $versionQuery = $this->database->table('estimate_regional_price_versions')
            ->whereIn('status', ['active', 'checked']);
        if (is_int($values['regional_price_version_id'])) {
            $versionQuery->where('id', $values['regional_price_version_id']);
        } else {
            if (! is_int($values['region_id'])) {
                return $values;
            }
        }
        foreach (['region_id', 'price_zone_id', 'period_id'] as $key) {
            if (is_int($values[$key])) {
                $versionQuery->where($key, $values[$key]);
            }
        }
        if (is_string($values['price_version']) && $values['price_version'] !== '') {
            $versionQuery->where('version_key', $values['price_version']);
        }
        $version = $versionQuery
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'checked' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->first(['id', 'region_id', 'price_zone_id', 'period_id', 'version_key']);
        if ($version !== null) {
            $values['regional_price_version_id'] ??= (int) $version->id;
            $values['region_id'] ??= (int) $version->region_id;
            $values['price_zone_id'] ??= (int) $version->price_zone_id;
            $values['period_id'] ??= (int) $version->period_id;
            $values['price_version'] ??= (string) $version->version_key;
        }

        return $values;
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

    /** @return list<array{search_text: string, unit: string, code?: string|null, action?: string|null, scope?: string|null, system?: string|null, object?: string|null, normative_section?: string|null, normative_sections?: list<string>}>|null */
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
            $action = isset($intent['action']) ? trim((string) $intent['action']) : null;
            $scope = isset($intent['scope']) ? trim((string) $intent['scope']) : null;
            $system = isset($intent['system']) ? trim((string) $intent['system']) : null;
            $object = isset($intent['object']) ? trim((string) $intent['object']) : null;
            $normativeSection = isset($intent['normative_section']) ? trim((string) $intent['normative_section']) : null;
            $normativeSections = is_array($intent['normative_sections'] ?? null)
                ? array_values(array_unique(array_filter(array_map(
                    static fn (mixed $section): string => is_string($section) ? trim($section) : '',
                    $intent['normative_sections'],
                ))))
                : [];
            if ($normativeSections === [] && $normativeSection !== null && $normativeSection !== '') {
                $normativeSections = [$normativeSection];
            }
            if ($search === '' || mb_strlen($search) > 500 || $unit === '' || mb_strlen($unit) > 32
                || ($code !== null && mb_strlen($code) > 80)
                || ($action !== null && mb_strlen($action) > 80)
                || ($scope !== null && mb_strlen($scope) > 80)
                || ($system !== null && mb_strlen($system) > 80)
                || ($object !== null && mb_strlen($object) > 80)
                || ($normativeSection !== null && mb_strlen($normativeSection) > 32)
                || count($normativeSections) > 8
                || array_filter($normativeSections, static fn (string $section): bool => mb_strlen($section) > 32) !== []) {
                continue;
            }
            $key = implode('|', array_map(mb_strtolower(...), [
                $search, $unit, (string) $code, (string) $action, (string) $scope,
                (string) $system, (string) $object, implode(',', $normativeSections),
            ]));
            $resolved[$key] = ['search_text' => $search, 'unit' => $unit, 'code' => $code];
            foreach (['action' => $action, 'scope' => $scope, 'system' => $system, 'object' => $object] as $field => $value) {
                if ($value !== null && $value !== '') {
                    $resolved[$key][$field] = $value;
                }
            }
            if ($normativeSections !== []) {
                $resolved[$key]['normative_sections'] = $normativeSections;
                if (count($normativeSections) === 1) {
                    $resolved[$key]['normative_section'] = $normativeSections[0];
                }
            }
            if (count($resolved) > 64) {
                return null;
            }
        }

        return array_values($resolved);
    }
}
