<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialProjectMaterialCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final readonly class AuthoritativePackagePricingGuard
{
    public function __construct(private AcceptedQuantityEvidenceVerifier $evidence) {}

    /** @param array<string, mixed> $workItem @return list<array{norm_resource_id: int, resource_price_id: int, unit_conversion_id: int|null, pinned_abstract_resource_conversion_id: int|null}>|null */
    public function inputs(int $organizationId, int $projectId, int $sessionId, string $currentVersion, array $workItem): ?array
    {
        if (! $this->evidence->verifyScope($organizationId, $projectId, $sessionId, $currentVersion, $workItem)) {
            return null;
        }
        $inputs = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                $reference = is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : [];
                $normResourceId = $this->positiveInt($reference['norm_resource_id'] ?? null);
                $priceId = $this->positiveInt($reference['price_id'] ?? null);
                if ($normResourceId === null && is_array($resource['project_material_selection'] ?? null)) {
                    continue;
                }
                if ($normResourceId === null || $priceId === null) {
                    return null;
                }
                $abstractRuleId = $this->abstractResourceSelectionRuleId($resource);
                if ($abstractRuleId === false) {
                    return null;
                }
                $inputs[] = [
                    'norm_resource_id' => $normResourceId,
                    'resource_price_id' => $priceId,
                    'unit_conversion_id' => $abstractRuleId === null
                        ? $this->positiveInt($reference['unit_conversion_id'] ?? null)
                        : null,
                    'pinned_abstract_resource_conversion_id' => $abstractRuleId,
                ];
            }
        }

        return $inputs === [] ? null : $inputs;
    }

    /**
     * @param  array<string, mixed>  $workItem
     * @return list<array{resource_price_id: int, project_material_rule_id: int, selection: array<string, mixed>}>|null
     */
    public function projectMaterialInputs(
        int $organizationId,
        int $projectId,
        int $sessionId,
        string $currentVersion,
        array $workItem,
    ): ?array {
        if (! $this->evidence->verifyScope($organizationId, $projectId, $sessionId, $currentVersion, $workItem)) {
            return null;
        }

        $inputs = [];
        foreach (['materials', 'labor', 'machinery', 'other_resources'] as $group) {
            foreach ($workItem[$group] ?? [] as $resource) {
                if (! is_array($resource) || ! is_array($resource['project_material_selection'] ?? null)) {
                    continue;
                }

                $reference = is_array($resource['normative_ref'] ?? null) ? $resource['normative_ref'] : [];
                if ($this->positiveInt($reference['norm_resource_id'] ?? null) !== null) {
                    return null;
                }
                $priceId = $this->positiveInt($reference['price_id'] ?? null);
                $selection = $this->projectMaterialSelection($resource, $reference);
                $rule = $selection === null ? null : $this->projectMaterialRule($selection);
                if ($priceId === null || $selection === null || $rule === null
                    || ! $this->conjunctureSelectionMatchesPrice($priceId, $selection)
                    || ! $this->sameDecimal($resource['quantity_per_unit'] ?? null, $rule->quantity_per_work_unit ?? null)
                    || ! $this->sameDecimal($selection['price_conversion_factor'] ?? null, $rule->price_factor ?? null)
                    || (string) ($resource['unit'] ?? '') !== (string) ($rule->material_unit ?? '')
                    || (string) ($selection['source_price_unit'] ?? '') !== (string) ($rule->source_unit ?? '')) {
                    return null;
                }

                $inputs[] = [
                    'resource_price_id' => $priceId,
                    'project_material_rule_id' => (int) $rule->id,
                    'selection' => $selection,
                ];
            }
        }

        return $inputs;
    }

    private function abstractResourceSelectionRuleId(array $resource): int|false|null
    {
        $selection = is_array($resource['project_resource_selection'] ?? null)
            ? $resource['project_resource_selection']
            : [];
        $ruleKey = trim((string) ($selection['abstract_selection_rule_key'] ?? ''));
        if ($ruleKey === '') {
            return null;
        }
        $ruleVersion = $this->positiveInt($selection['abstract_selection_rule_version'] ?? null);
        $priceFactor = $selection['conversion_factor'] ?? null;
        $quantityFactor = $selection['quantity_factor'] ?? null;
        $assumption = trim((string) ($selection['conversion_assumption'] ?? ''));
        $sourceUnit = trim((string) ($selection['source_price_unit'] ?? ''));
        if ($ruleVersion === null || ! is_numeric($priceFactor) || (float) $priceFactor <= 0
            || ! is_numeric($quantityFactor) || (float) $quantityFactor <= 0
            || $assumption === '' || $sourceUnit === '') {
            return false;
        }
        $ruleId = DB::table('estimate_generation_pinned_abstract_resource_conversions')
            ->where('rule_key', $ruleKey)
            ->where('version', $ruleVersion)
            ->where('from_unit', $sourceUnit)
            ->where('assumption', $assumption)
            ->where('monetary_factor', (string) $priceFactor)
            ->where('quantity_factor', (string) $quantityFactor)
            ->value('id');

        return $this->positiveInt($ruleId) ?? false;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 ? (int) $value : null;
    }

    private function sameDecimal(mixed $left, mixed $right): bool
    {
        if ((! is_int($left) && ! is_float($left) && ! is_string($left))
            || (! is_int($right) && ! is_float($right) && ! is_string($right))) {
            return false;
        }

        $left = trim((string) $left);
        $right = trim((string) $right);
        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $left) !== 1
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D', $right) !== 1) {
            return false;
        }

        try {
            $leftDecimal = BigDecimal::of($left);
            $rightDecimal = BigDecimal::of($right);
        } catch (\Throwable) {
            return false;
        }

        return $leftDecimal->isEqualTo($rightDecimal) && $leftDecimal->isGreaterThan(0);
    }

    /**
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $reference
     * @return array<string, mixed>|null
     */
    private function projectMaterialSelection(array $resource, array $reference): ?array
    {
        $selection = $resource['project_material_selection'];
        $required = [
            'version',
            'work_item_key',
            'assumption_code',
            'selection_policy',
            'source_unit_price',
            'source_price_unit',
            'price_conversion_factor',
            'candidate_pool_version',
        ];
        foreach ($required as $key) {
            if (! is_scalar($selection[$key] ?? null) || trim((string) $selection[$key]) === '') {
                return null;
            }
        }
        $candidatePriceIds = $selection['candidate_resource_price_ids'] ?? null;
        if ($selection['candidate_pool_version'] !== ResidentialProjectMaterialCatalog::CANDIDATE_POOL_VERSION) {
            return null;
        }
        if (! is_array($candidatePriceIds) || ! array_is_list($candidatePriceIds)
            || $candidatePriceIds === [] || count($candidatePriceIds) > ResidentialProjectMaterialCatalog::MAX_CANDIDATE_PRICE_IDS) {
            return null;
        }
        $candidatePriceIds = array_map($this->positiveInt(...), $candidatePriceIds);
        if (in_array(null, $candidatePriceIds, true) || count(array_unique($candidatePriceIds)) !== count($candidatePriceIds)) {
            return null;
        }
        sort($candidatePriceIds, SORT_NUMERIC);
        $selection['candidate_resource_price_ids'] = $candidatePriceIds;

        $resourceCode = trim((string) ($resource['code'] ?? $reference['resource_code'] ?? ''));
        $resourceName = trim((string) ($resource['name'] ?? ''));
        $priceUnit = trim((string) ($resource['price_unit'] ?? $resource['unit'] ?? ''));
        $priceSource = trim((string) ($resource['price_source'] ?? $reference['price_source'] ?? ''));
        $priceSourceVersion = trim((string) ($resource['price_source_version'] ?? $reference['price_source_version'] ?? ''));
        if ($resourceCode === '' || $resourceName === '' || $priceUnit === '' || $priceSource === '' || $priceSourceVersion === '') {
            return null;
        }

        return [
            ...$selection,
            'resource_code' => $resourceCode,
            'resource_name' => $resourceName,
            'price_unit' => $priceUnit,
            'price_source' => $priceSource,
            'price_source_version' => $priceSourceVersion,
        ];
    }

    /** @param array<string, mixed> $selection */
    private function conjunctureSelectionMatchesPrice(int $priceId, array $selection): bool
    {
        if (($selection['price_source_kind'] ?? null) !== 'conjuncture_analysis') {
            return ! isset($selection['price_provenance']);
        }

        $provenance = $selection['price_provenance'] ?? null;
        if (! is_array($provenance) || ($provenance['schema_version'] ?? null) !== 'project_material_conjuncture:v1') {
            return false;
        }

        $row = DB::table('estimate_resource_prices')
            ->where('id', $priceId)
            ->first(['source_price_kind', 'raw_payload']);
        if (! is_object($row) || ($row->source_price_kind ?? null) !== 'conjuncture_analysis') {
            return false;
        }

        $raw = $row->raw_payload ?? null;
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) && is_array($raw['analysis'] ?? null)
            && $raw['analysis'] == $provenance;
    }

    /** @param array<string, mixed> $selection */
    private function projectMaterialRule(array $selection): ?object
    {
        $rule = DB::table('estimate_generation_project_material_rules')
            ->where('catalog_version', (string) $selection['version'])
            ->where('work_item_key', (string) $selection['work_item_key'])
            ->where('assumption_code', (string) $selection['assumption_code'])
            ->first();

        return is_object($rule) && (int) ($rule->id ?? 0) > 0 ? $rule : null;
    }
}
