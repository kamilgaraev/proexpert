<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\PackagePlanData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CanonicalTechnologyWorkItemPlanner;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DirectTakeoffRequiredWorkItems;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantitySource;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentEvidencePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use Throwable;

final class NormativeWorkItemPlannerService
{
    public function __construct(
        private readonly ProjectDocumentNormativeReferenceExtractor $projectDocumentNormativeReferenceExtractor,
        private readonly EstimatorScopeInferenceService $scopeInferenceService,
        private readonly ?ResidentialMaterialScenarioCatalog $materialScenarioCatalog = null,
        private readonly RoofTypeResolver $roofTypeResolver = new RoofTypeResolver,
        private readonly PackagePlannerService $packagePlanner = new PackagePlannerService,
    ) {}

    /**
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    public function build(array $localEstimate, array $section, array $analysis, ?int $workItemLimit = null, ?array $projectReferences = null, ?PackagePlanData $packagePlan = null): array
    {
        $quantityModel = $this->documentQuantityModel($analysis);
        $items = $this->projectReferenceItems($projectReferences ?? $this->projectDocumentNormativeReferenceExtractor->extract($analysis, $localEstimate, $section, $workItemLimit), $localEstimate, $section);

        foreach ($this->definitions($localEstimate, $section, $analysis, $quantityModel, $packagePlan) as $index => $definition) {
            $item = $this->workItemFromDefinition($definition, $localEstimate, $section, $analysis, $quantityModel, $index);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $this->uniquePricedItems($items);
    }

    /** @return list<array<string,mixed>> */
    public function projectReferenceItems(array $references, array $localEstimate, array $section): array
    {
        $items = [];
        foreach ($references as $index => $reference) {
            $items[] = $this->workItemFromProjectReference($reference, $localEstimate, $section, $index);
        }

        return $this->uniquePricedItems($items);
    }

    /** @return list<array<string, mixed>> */
    public static function stageFivePackageItems(array $packages): array
    {
        return (new CanonicalTechnologyWorkItemPlanner)->planPackages($packages);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    private function workItemFromProjectReference(array $reference, array $localEstimate, array $section, int $index): array
    {
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $key = $packageKey.'-project-ref-'.($index + 1);

        $quantity = $reference['quantity'] ?? null;
        $item = $this->basePricedWorkItem(
            key: $key,
            localEstimate: $localEstimate,
            section: $section,
            name: (string) $reference['name'],
            searchText: (string) ($reference['normative_search_text'] ?? $reference['name']),
            category: (string) $reference['work_category'],
            unit: isset($reference['unit']) ? (string) $reference['unit'] : null,
            quantity: $quantity === null ? null : (float) $quantity,
            quantityFormula: (string) $reference['quantity_formula'],
            quantityBasis: (string) $reference['quantity_basis'],
            sourceRefs: $reference['source_refs'] ?? [],
            confidence: (float) $reference['confidence'],
            validationFlags: $reference['validation_flags'] ?? ['normative_required'],
            metadata: $reference['metadata'] ?? [],
            normativeRateCode: isset($reference['normative_rate_code']) ? (string) $reference['normative_rate_code'] : null,
            operations: $this->operationBank((string) $reference['work_category'])
        );
        if ($quantity === null) {
            $item['item_type'] = 'quantity_review';
            $item['pricing_status'] = 'not_applicable';
            $item['pricing_blocker'] = 'quantity_review_required';
            $item['validation_flags'] = array_values(array_unique([...$item['validation_flags'], 'quantity_review_required']));
            if ($item['unit'] === null) {
                $item['validation_flags'][] = 'unit_requires_confirmation';
            }
            $item['metadata']['display_role'] = 'quantity_review';
            $item['metadata']['normative_grounding_policy'] = 'quantity_confirmation_required';
        }

        return $item;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $quantityModel
     * @return array<string, mixed>|null
     */
    private function workItemFromDefinition(
        array $definition,
        array $localEstimate,
        array $section,
        array $analysis,
        array $quantityModel,
        int $index
    ): ?array {
        if (($definition['quantity_key'] ?? null) === 'office.ceiling'
            && ! isset($quantityModel['explicit_quantity_keys']['office.ceiling'])) {
            return null;
        }
        $definition = $this->withResidentialMaterialScenario($definition, $analysis);
        if (! $this->allowsRoofCompositionPricing($definition, $analysis)) {
            return null;
        }
        $quantity = $this->quantityForDefinition($definition, $analysis, $quantityModel);

        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $key = $packageKey.'-norm-intent-'.($index + 1);

        if ($this->isPlannerFallbackQuantity($quantity)) {
            if (($analysis['evaluation_policy']['mode'] ?? null) === 'universal') {
                return $this->unknownQuantityWorkItem($definition, $localEstimate, $section, $key);
            }

            return null;
        }

        if (($quantity['review_required'] ?? false) === true) {
            return $this->quantityReviewItemFromDefinition(
                key: $key,
                definition: $definition,
                localEstimate: $localEstimate,
                section: $section,
                quantity: $quantity
            );
        }

        return $this->basePricedWorkItem(
            key: $key,
            localEstimate: $localEstimate,
            section: $section,
            name: (string) $definition['name'],
            searchText: (string) ($definition['normative_search_text'] ?? $definition['name']),
            category: (string) $definition['category'],
            unit: (string) $quantity['unit'],
            quantity: (float) $quantity['value'],
            quantityFormula: (string) $definition['quantity_key'],
            quantityBasis: (string) $quantity['basis'],
            sourceRefs: $quantity['source_refs'] !== [] ? $quantity['source_refs'] : ($section['source_refs'] ?? $localEstimate['source_refs'] ?? []),
            confidence: $this->plannedQuantityConfidence($definition, $quantity, 0.7),
            validationFlags: [
                'normative_required',
                ...$this->materialScenarioFlags($definition),
                ...(($quantity['source'] ?? null) === 'residential_preliminary_scenario'
                    ? ['preliminary_quantity_scenario']
                    : []),
            ],
            metadata: [
                'generation_source' => $definition['generation_source'] ?? 'normative_intent_catalog',
                'quantity_key' => $definition['quantity_key'],
                'quantity_source' => $quantity['source'],
                'package_key' => $packageKey,
                ...($definition['metadata'] ?? []),
                ...$this->quantityLearningMetadata($quantity),
            ],
            normativeRateCode: isset($definition['normative_rate_code']) ? (string) $definition['normative_rate_code'] : null,
            operations: $definition['operations'] ?? $this->operationBank((string) $definition['category'])
        );
    }

    private function unknownQuantityWorkItem(array $definition, array $estimate, array $section, string $key): array
    {
        return ['key' => $key, 'parent_key' => null, 'level' => 0, 'item_type' => 'priced_work',
            'name' => $definition['name'], 'description' => $definition['normative_search_text'],
            'normative_search_text' => $definition['normative_search_text'], 'normative_rate_code' => null,
            'work_category' => $definition['category'], 'unit' => $definition['unit'] ?? null, 'quantity' => null,
            'quantity_formula' => $definition['quantity_key'], 'quantity_basis' => 'Количество отсутствует в исходных данных.',
            'work_cost' => null, 'materials_cost' => null, 'machinery_cost' => null, 'labor_cost' => null, 'total_cost' => null,
            'materials' => [], 'labor' => [], 'machinery' => [], 'other_resources' => [],
            'work_composition' => $definition['operations'], 'source_refs' => [], 'confidence' => 0,
            'validation_flags' => isset($definition['unit']) ? ['quantity_unknown'] : ['quantity_unknown', 'unit_requires_confirmation'], 'price_source' => null,
            'pricing_status' => 'not_calculated', 'pricing_blocker' => 'quantity_unknown',
            'skip_normative_matching' => true,
            'metadata' => ['generation_source' => 'qualitative_work_catalog', 'quantity_key' => $definition['quantity_key'],
                'quantity_source' => 'unknown', 'package_key' => $estimate['key'], 'section_key' => $section['key'], ...($definition['metadata'] ?? [])]];
    }

    /** @param array<string, mixed> $definition */
    private function allowsRoofCompositionPricing(array $definition, array $analysis): bool
    {
        $workItemKey = $this->materialScenarioWorkItemKey($definition);
        if (! in_array($workItemKey, [
            'roof.rafters',
            'roof.insulation',
            'roof.vapor_barrier',
            'roof.membrane',
            'roof.battens',
            'roof.gutter',
        ], true)) {
            return true;
        }

        return $this->trustedSpecializationEvidence($analysis, $workItemKey) !== [];
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @param  array<int, array<string, mixed>>  $sourceRefs
     * @param  array<int, string>  $validationFlags
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $operations
     * @return array<string, mixed>
     */
    private function basePricedWorkItem(
        string $key,
        array $localEstimate,
        array $section,
        string $name,
        string $searchText,
        string $category,
        ?string $unit,
        ?float $quantity,
        string $quantityFormula,
        string $quantityBasis,
        array $sourceRefs,
        float $confidence,
        array $validationFlags,
        array $metadata,
        ?string $normativeRateCode,
        array $operations
    ): array {
        $packageKey = (string) ($localEstimate['key'] ?? 'package');
        $scopeType = (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? 'custom');
        $searchKey = $this->normativeSearchKey($packageKey, $scopeType, $category, $searchText, $unit ?? '', $quantityFormula, $normativeRateCode);

        return [
            'key' => $key,
            'parent_key' => null,
            'level' => 0,
            'item_type' => 'priced_work',
            'name' => $name,
            'description' => $searchText,
            'normative_search_text' => $searchText,
            'normative_search_key' => $searchKey,
            'normative_rate_code' => $normativeRateCode,
            'work_category' => $category,
            'unit' => $unit,
            'quantity' => $quantity === null ? null : $this->canonicalPlannedQuantity($quantity),
            'quantity_formula' => $quantityFormula,
            'quantity_basis' => $quantityBasis,
            'work_cost' => $quantity === null ? null : 0,
            'materials_cost' => $quantity === null ? null : 0,
            'machinery_cost' => $quantity === null ? null : 0,
            'labor_cost' => $quantity === null ? null : 0,
            'total_cost' => $quantity === null ? null : 0,
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_composition' => array_values($operations),
            'source_refs' => $this->normalizeSourceRefs($sourceRefs),
            'confidence' => round(max(min($confidence, 0.98), 0.35), 4),
            'validation_flags' => array_values(array_unique($validationFlags)),
            'price_source' => null,
            'pricing_status' => 'not_calculated',
            'pricing_blocker' => 'normative_required',
            'pricing_blocker_message' => null,
            ...(
                isset($metadata['specialization_scenario']) && is_array($metadata['specialization_scenario'])
                    ? ['specialization_scenario' => $metadata['specialization_scenario']]
                    : []
            ),
            ...(
                isset($metadata['specialization_evidence']) && is_array($metadata['specialization_evidence'])
                    ? ['specialization_evidence' => $metadata['specialization_evidence']]
                    : []
            ),
            'metadata' => [
                ...$metadata,
                'normative_grounding_policy' => 'fsnb_required',
                'display_role' => 'priced_work',
                'work_composition' => array_values($operations),
                'composition_source' => 'planner_intent',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    private function withResidentialMaterialScenario(array $definition, array $analysis): array
    {
        if (! $this->isResidentialAnalysis($analysis)) {
            return $definition;
        }

        $workItemKey = $this->materialScenarioWorkItemKey($definition);
        if ($workItemKey === '') {
            return $definition;
        }
        $compositionWorkKey = (string) ($definition['metadata']['composition_work_key'] ?? '');
        if ($compositionWorkKey === '' || $compositionWorkKey === (string) ($definition['quantity_key'] ?? '')) {
            $compositionWorkKey = $workItemKey;
        }
        $definition['metadata'] = [
            ...(is_array($definition['metadata'] ?? null) ? $definition['metadata'] : []),
            'composition_work_key' => $compositionWorkKey,
        ];

        $trustedEvidence = $this->trustedSpecializationEvidence($analysis, $workItemKey);
        if ($trustedEvidence !== []) {
            return $this->withTrustedSpecializationEvidence($definition, $trustedEvidence);
        }

        $catalog = $this->materialScenarioCatalog ?? new ResidentialMaterialScenarioCatalog;
        $scenario = $catalog->issue($workItemKey, 'residential');
        if ($scenario === null) {
            return $definition;
        }

        $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
        $assumptionCode = (string) $scenario['assumption_code'];
        $translationKey = 'estimate_generation.material_scenarios.'.$assumptionCode;
        $definition['metadata'] = [
            ...$metadata,
            'material_scenario_work_key' => $workItemKey,
            'specialization_scenario' => $scenario,
            'material_assumption' => [
                'code' => $assumptionCode,
                'translation_key' => $translationKey,
                'message' => $this->materialScenarioMessage($assumptionCode),
                'severity' => 'warning',
                'requires_confirmation' => true,
                'scenario_id' => $scenario['scenario_id'],
                'version' => $scenario['version'],
            ],
        ];
        if (is_string($scenario['normative_search_text'] ?? null)
            && trim($scenario['normative_search_text']) !== '') {
            $definition['normative_search_text'] = trim($scenario['normative_search_text']);
        }
        if (is_string($scenario['work_item_name'] ?? null)
            && trim($scenario['work_item_name']) !== '') {
            $definition['name'] = trim($scenario['work_item_name']);
        }
        if (is_string($scenario['normative_rate_code'] ?? null)
            && trim($scenario['normative_rate_code']) !== '') {
            $definition['normative_rate_code'] = trim($scenario['normative_rate_code']);
        }

        return $definition;
    }

    /** @param array<string, mixed> $definition */
    private function materialScenarioWorkItemKey(array $definition): string
    {
        $quantityKey = (string) ($definition['quantity_key'] ?? '');
        if ($quantityKey !== 'roof.area') {
            return $quantityKey;
        }

        $text = mb_strtolower((string) ($definition['normative_search_text'] ?? $definition['name'] ?? ''));

        return match (true) {
            str_contains($text, 'утепл') => 'roof.insulation',
            str_contains($text, 'покрыт') || str_contains($text, 'кровл') => 'roof.covering',
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<array<string, mixed>>
     */
    private function trustedSpecializationEvidence(array $analysis, string $workItemKey): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null)
            ? $analysis['document_context']
            : [];
        $sources = [
            $analysis['specialization_evidence'] ?? null,
            $analysis['material_evidence'] ?? null,
            $documentContext['specialization_evidence'] ?? null,
            $documentContext['material_evidence'] ?? null,
        ];
        $result = [];

        foreach ($sources as $source) {
            if (! is_array($source) || ! is_array($source[$workItemKey] ?? null)) {
                continue;
            }
            foreach ($source[$workItemKey] as $evidence) {
                if (! is_array($evidence)
                    || ! in_array($evidence['source'] ?? null, ['document', 'decision'], true)) {
                    continue;
                }
                $text = trim((string) ($evidence['text'] ?? ''));
                $refs = is_array($evidence['evidence_refs'] ?? null)
                    ? array_values(array_unique(array_filter(
                        $evidence['evidence_refs'],
                        static fn (mixed $ref): bool => is_string($ref) && trim($ref) !== '',
                    )))
                    : [];
                if ($text === '' || $refs === []) {
                    continue;
                }
                $search = trim((string) ($evidence['normative_search_text'] ?? ''));
                $code = trim((string) ($evidence['normative_rate_code'] ?? ''));
                $result[] = array_filter([
                    'text' => mb_substr($text, 0, 2000),
                    'source' => $evidence['source'],
                    'evidence_refs' => $refs,
                    'normative_search_text' => $search !== '' ? mb_substr($search, 0, 500) : null,
                    'normative_rate_code' => preg_match('/^\d{2}-\d{2}-\d{3}-\d{2}$/', $code) === 1 ? $code : null,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
            }
        }

        return array_slice($result, 0, 32);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $evidence
     * @return array<string, mixed>
     */
    private function withTrustedSpecializationEvidence(array $definition, array $evidence): array
    {
        $metadata = is_array($definition['metadata'] ?? null) ? $definition['metadata'] : [];
        $workItemKey = $this->materialScenarioWorkItemKey($definition);
        $catalog = $this->materialScenarioCatalog ?? new ResidentialMaterialScenarioCatalog;
        $scenario = $catalog->issue($workItemKey, 'residential');
        $keepScenario = is_array($scenario) && $this->trustedEvidenceMatchesScenario($evidence, $scenario);
        unset($metadata['material_assumption']);
        if ($keepScenario) {
            $metadata['material_scenario_work_key'] = $workItemKey;
            $metadata['specialization_scenario'] = $scenario;
        } else {
            unset($metadata['specialization_scenario'], $metadata['material_scenario_work_key']);
        }

        $searches = $this->uniqueEvidenceValues($evidence, 'normative_search_text');
        $codes = $this->uniqueEvidenceValues($evidence, 'normative_rate_code');
        $evidenceText = implode(' ', array_column($evidence, 'text'));
        $definition['normative_search_text'] = count($searches) === 1
            ? $searches[0]
            : trim((string) ($definition['normative_search_text'] ?? $definition['name'] ?? '').' '.$evidenceText);
        $definition['normative_rate_code'] = count($codes) === 1 ? $codes[0] : null;
        $definition['metadata'] = [
            ...$metadata,
            'specialization_evidence' => $evidence,
            'material_evidence_priority' => 'trusted_source',
        ];

        return $definition;
    }

    private function trustedEvidenceMatchesScenario(array $evidence, array $scenario): bool
    {
        $codes = $this->uniqueEvidenceValues($evidence, 'normative_rate_code');
        $scenarioCode = trim((string) ($scenario['normative_rate_code'] ?? ''));
        if ($codes !== []) {
            return count($codes) === 1 && $scenarioCode !== '' && $codes[0] === $scenarioCode;
        }

        $markers = array_values(array_filter(
            $scenario['material_markers'] ?? [],
            static fn (mixed $marker): bool => is_string($marker) && trim($marker) !== '',
        ));
        if ($markers === []) {
            return false;
        }

        $text = mb_strtolower(implode(' ', array_map(
            static fn (array $item): string => (string) ($item['text'] ?? ''),
            $evidence,
        )));

        foreach ($markers as $marker) {
            if (str_contains($text, mb_strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @return list<string>
     */
    private function uniqueEvidenceValues(array $evidence, string $key): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (array $item): string => trim((string) ($item[$key] ?? '')), $evidence),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /** @param array<string, mixed> $analysis */
    private function isResidentialAnalysis(array $analysis): bool
    {
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];

        foreach ([
            $object['object_type'] ?? null,
            $object['building_type'] ?? null,
            $object['description'] ?? null,
            $factsSummary['object_type'] ?? null,
            $factsSummary['building_type'] ?? null,
        ] as $value) {
            if (is_string($value) && ObjectTypeSignalClassifier::isResidential($value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $definition @return list<string> */
    private function materialScenarioFlags(array $definition): array
    {
        return isset($definition['metadata']['specialization_scenario']) ? ['preliminary_material_assumption'] : [];
    }

    private function materialScenarioMessage(string $assumptionCode): string
    {
        $fallback = match ($assumptionCode) {
            'foundation_coating_waterproofing' => 'Предварительно принята обмазочная мастичная гидроизоляция фундамента. Материал нужно уточнить по проекту.',
            'external_walls_aerated_concrete' => 'Предварительно приняты наружные стены из газобетонных блоков. Материал нужно уточнить по проекту.',
            'internal_partitions_aerated_concrete' => 'Предварительно приняты внутренние перегородки из газобетонных блоков. Материал нужно уточнить по проекту.',
            'pitched_roof_mineral_wool' => 'Предварительно принято утепление скатной кровли минераловатными плитами. Материал нужно уточнить по проекту.',
            'pitched_roof_metal_tile' => 'Предварительно принято покрытие простой скатной кровли металлочерепицей. Материал и сложность кровли нужно уточнить по проекту.',
            'pitched_roof_single_layer_vapor_barrier' => 'Предварительно принята однослойная пароизоляция скатной кровли. Тип материала нужно уточнить по проекту.',
            'pitched_roof_diffusion_membrane' => 'Предварительно принята подкровельная гидроизоляционная диффузионная мембрана. Для монтажа использована расценка укладки одного прокладочного листового слоя с заменой материала ресурса на мембрану; тип материала нужно уточнить по проекту.',
            'pitched_roof_timber_battens' => 'Предварительно принята деревянная обрешетка скатной кровли. Сечение и шаг нужно уточнить по проекту.',
            'floor_laminate' => 'Предварительно принято чистовое покрытие пола из ламината. Материал нужно уточнить по ведомости отделки.',
            'baseboard_pvc' => 'Предварительно принят плинтус из ПВХ. Материал нужно уточнить по ведомости отделки.',
            'residential_small_galvanized_ducts' => 'Предварительно приняты воздуховоды из оцинкованной стали класса Н диаметром до 200 мм. Материал и сечение нужно уточнить по проекту вентиляции.',
            'residential_stair_without_soffit' => 'Предварительно принята внутриквартирная лестница без подшивки. Конструкцию и материал нужно уточнить по проекту.',
            'residential_pvc_windows' => 'Предварительно приняты двухстворчатые оконные блоки из ПВХ площадью до 2 м². Типы и размеры нужно уточнить по спецификации окон.',
            'residential_round_steel_grounding' => 'Предварительно принят горизонтальный заземлитель из круглой стали диаметром 12 мм. Схему и материал нужно уточнить по проекту электроснабжения.',
            'residential_steel_radiators' => 'Предварительно приняты стальные радиаторы и расчётная тепловая мощность 0,10 кВт на 1 м². Оборудование и тепловую нагрузку нужно уточнить по проекту отопления.',
            'residential_aluminium_or_bimetallic_radiator_sections' => 'Предварительно приняты алюминиевые или биметаллические радиаторы по 8 секций из расчёта 0,10 кВт на 1 м² и 0,18 кВт на секцию. Количество в смете указано в приборах, как требует норма. Модель, число секций и тепловую нагрузку нужно уточнить по проекту отопления.',
            'wet_zone_coating_waterproofing' => 'Предварительно принята обмазочная гидроизоляция мокрых зон в один слой толщиной 2 мм. Материал нужно уточнить по ведомости отделки.',
            'wet_zone_ceramic_wall_tile' => 'Предварительно принята облицовка стен мокрых зон керамической плиткой на клее. Материал и высоту облицовки нужно уточнить по ведомости отделки.',
            'wet_zone_ceramic_floor_tile' => 'Предварительно принято покрытие пола мокрых зон одноцветной керамической плиткой на цементном растворе. Материал нужно уточнить по ведомости отделки.',
            'residential_internal_sewer_pp_50mm' => 'Предварительно принята внутренняя канализация из полипропиленовых труб диаметром 50 мм. Расценка включает прокладку горизонтальных участков и стояков с установкой фасонных частей и ревизий; диаметры и трассировку нужно уточнить по проекту.',
            'residential_sewer_outlet_pp_110mm' => 'Предварительно принят канализационный выпуск из полипропиленовой трубы диаметром 110 мм. Материал, диаметр и трассировку нужно уточнить по проекту канализации.',
            'residential_electric_boiler_installation_analog_30kg' => 'Предварительно принят электрический котёл отопления массой до 30 кг. Монтаж рассчитан по аналогу установки оборудования в помещении; модель, массу и подключения нужно уточнить по проекту отопления.',
            default => $assumptionCode,
        };

        return $this->estimateGenerationMessage('material_scenarios.'.$assumptionCode, $fallback);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $quantity
     * @return array<string, mixed>
     */
    private function quantityReviewItemFromDefinition(
        string $key,
        array $definition,
        array $localEstimate,
        array $section,
        array $quantity
    ): array {
        $packageKey = (string) ($localEstimate['key'] ?? 'package');

        return [
            'key' => $key,
            'parent_key' => null,
            'level' => 0,
            'item_type' => 'quantity_review',
            'name' => (string) $definition['name'],
            'description' => (string) ($definition['normative_search_text'] ?? $definition['name']),
            'normative_search_text' => (string) ($definition['normative_search_text'] ?? $definition['name']),
            'normative_search_key' => $this->normativeSearchKey(
                $packageKey,
                (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? 'custom'),
                (string) $definition['category'],
                (string) ($definition['normative_search_text'] ?? $definition['name']),
                (string) $quantity['unit'],
                (string) $definition['quantity_key'],
                isset($definition['normative_rate_code']) ? (string) $definition['normative_rate_code'] : null
            ),
            'normative_rate_code' => isset($definition['normative_rate_code']) ? (string) $definition['normative_rate_code'] : null,
            'work_category' => (string) $definition['category'],
            'unit' => (string) $quantity['unit'],
            'quantity' => $this->canonicalPlannedQuantity((float) $quantity['value']),
            'quantity_formula' => (string) $definition['quantity_key'],
            'quantity_basis' => (string) $quantity['basis'],
            'work_cost' => 0,
            'materials_cost' => 0,
            'machinery_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
            'materials' => [],
            'labor' => [],
            'machinery' => [],
            'other_resources' => [],
            'work_composition' => array_values($definition['operations'] ?? $this->operationBank((string) $definition['category'])),
            'source_refs' => $this->normalizeSourceRefs($quantity['source_refs'] ?? []),
            'confidence' => round(max(min($this->plannedQuantityConfidence($definition, $quantity, 0.7), 0.98), 0.35), 4),
            'validation_flags' => ['quantity_review_required'],
            'price_source' => null,
            'pricing_status' => 'not_applicable',
            'pricing_blocker' => 'quantity_review_required',
            'pricing_blocker_message' => null,
            'metadata' => [
                'generation_source' => $definition['generation_source'] ?? 'normative_intent_catalog',
                'quantity_key' => (string) $definition['quantity_key'],
                'quantity_source' => (string) ($quantity['source'] ?? 'document_quantity'),
                'package_key' => $packageKey,
                ...($definition['metadata'] ?? []),
                ...$this->quantityLearningMetadata($quantity),
                'normative_grounding_policy' => 'quantity_confirmation_required',
                'display_role' => 'quantity_review',
                'work_composition' => array_values($definition['operations'] ?? $this->operationBank((string) $definition['category'])),
                'composition_source' => 'planner_intent',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $quantityModel
     * @return array<int, array<string, mixed>>
     */
    private function definitions(array $localEstimate, array $section, array $analysis, array $quantityModel, ?PackagePlanData $packagePlan): array
    {
        $packageKey = (string) ($localEstimate['key'] ?? '');
        $scopeType = (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? '');
        $universal = ($analysis['evaluation_policy']['mode'] ?? null) === 'universal';
        $selectedResidentialEquipment = $packageKey === 'equipment' && $universal
            && ($analysis['evaluation_policy']['profile_id'] ?? null) !== 'industrial'
            && in_array('equipment', $analysis['evaluation_policy']['selected_sections'] ?? [], true)
            && ObjectTypeSignalClassifier::isResidential($this->packagePlanner->profileFromAnalysis($analysis)->objectType);
        $separateWaterScope = $universal && in_array($packageKey, ['plumbing', 'sewerage'], true);
        $openingQuantityKeys = match ($packageKey) {
            'gates' => ['warehouse.gates', 'warehouse.loading_nodes', 'openings.gates'],
            'entrance_group' => ['openings.windows', 'openings.doors'],
            default => null,
        };
        $packageDefinitions = $this->packageDefinitions($packageKey, $scopeType, $quantityModel, $analysis, $selectedResidentialEquipment);
        $sourceBackedQuantityKeys = $this->sourceBackedPackageQuantityKeys($packageDefinitions, $quantityModel);
        $definitions = [
            ...$packageDefinitions,
            ...($selectedResidentialEquipment ? [] : $this->scopeInferenceDefinitions(
                $analysis,
                $scopeType,
                $packageKey,
                $sourceBackedQuantityKeys
            )),
        ];

        $definitions = array_values(array_filter(
            $definitions,
            fn (array $definition): bool => $this->definitionMatchesObject($definition, $analysis)
                && (! $separateWaterScope || ($definition['category'] ?? null) === $scopeType)
                && ($openingQuantityKeys === null || in_array($definition['quantity_key'] ?? null, $openingQuantityKeys, true))
                && $this->definitionBelongsToPackage((string) ($definition['quantity_key'] ?? ''), $packageKey, $packagePlan)
                && ($universal || $this->definitionHasRequiredTakeoff($definition, $sourceBackedQuantityKeys))
        ));
        $confirmedSanitaryInferenceKeys = [];
        foreach ($definitions as $definition) {
            $inference = $definition['metadata']['scope_inference'] ?? null;
            $quantityKey = (string) ($definition['quantity_key'] ?? '');
            if (is_array($inference) && $this->isConfirmedSanitaryFixtureInference($inference, $quantityKey)) {
                $confirmedSanitaryInferenceKeys[$quantityKey] = true;
            }
        }

        return array_values(array_filter(
            $definitions,
            fn (array $definition): bool => $this->definitionHasSanitaryFixtureEvidence($definition, $analysis, $confirmedSanitaryInferenceKeys)
        ));
    }

    private function definitionBelongsToPackage(string $quantityKey, string $packageKey, ?PackagePlanData $plan): bool
    {
        $packages = $plan === null ? [] : array_column($plan->packages, 'key');
        $waterPackage = in_array('plumbing', $packages, true) ? 'plumbing' : 'water_sewerage';
        $separateSanitary = in_array('sanitary_rooms', $packages, true) && in_array($waterPackage, $packages, true);
        $owner = match (true) {
            str_starts_with($quantityKey, 'lighting.') || $quantityKey === 'warehouse.lighting' => 'lighting',
            in_array($quantityKey, ['warehouse.low_current', 'office.network_points'], true) => 'low_current',
            $quantityKey === 'server.room' => 'server_room',
            $separateSanitary && str_starts_with($quantityKey, 'sanitary.') => 'sanitary_rooms',
            $separateSanitary && $quantityKey === 'plumbing.pipe' => $waterPackage,
            default => null,
        };

        return $owner === null || ! in_array($owner, $packages, true) || $packageKey === $owner;
    }

    private function definitionHasSanitaryFixtureEvidence(array $definition, array $analysis, array $confirmedSanitaryInferenceKeys): bool
    {
        $quantityKey = (string) ($definition['quantity_key'] ?? '');
        if (! in_array($quantityKey, [
            'sanitary.points',
            'sanitary.showers',
            'sanitary.toilets',
            'sanitary.washbasins',
        ], true)) {
            return true;
        }

        $inference = $definition['metadata']['scope_inference'] ?? null;
        if (is_array($inference)) {
            return $this->isConfirmedSanitaryFixtureInference($inference, $quantityKey);
        }
        if ($this->hasConfirmedSanitaryFixtureTakeoff($analysis, $quantityKey)) {
            return true;
        }
        if (isset($confirmedSanitaryInferenceKeys[$quantityKey])) {
            return false;
        }

        if ($quantityKey === 'sanitary.points') {
            return false;
        }

        foreach ($this->sanitaryFixtureEvidenceTexts($analysis) as $text) {
            if ($this->hasLocalSanitaryFixtureStatement($text, $quantityKey)) {
                return true;
            }
        }

        return false;
    }

    private function hasLocalSanitaryFixtureStatement(string $text, string $quantityKey): bool
    {
        $fixturePatterns = [
            'sanitary.toilets' => 'унитаз(?:а|у|ом|е|ы|ов|ам|ами|ах)?',
            'sanitary.washbasins' => 'умывальник(?:а|у|е|и|ов|ом|ам|ами|ах)?',
            'sanitary.showers' => 'душев(?:ой|ого|ому|ым|ом|ая|ую|ые|ых|ыми)\s+кабин(?:а|ы|у|е|ой|ою|ам|ами|ах)?',
        ];
        $fixture = $fixturePatterns[$quantityKey] ?? null;
        if ($fixture === null) {
            return false;
        }
        $installationAction = '(?:устан(?:ов|ав)\p{L}*|монтаж\p{L}*|монтир\p{L}*|подключ\p{L}*)';
        $negativeAction = '(?:предусмотр\p{L}*|требу\p{L}*|планиру\p{L}*|запланир\p{L}*|нуж\p{L}*|вход\p{L}*|включ\p{L}*|'.$installationAction.')';
        $negativeFixtureAction = '(?:предусмотр\p{L}*|требу(?:ется|ются)|планиру\p{L}*|запланир\p{L}*|нуж\p{L}*|вход\p{L}*|включ(?:ен|ён)\p{L}*|'.$installationAction.')';
        $singularExclusionAction = '(?:исключ(?:ить|ая|ён|ен|ена|ено|ается)|отмен(?:ить|ён|ен|ена|ено|яется)|отсутствует)';
        $pluralExclusionAction = '(?:исключ(?:ены|аются)|отмен(?:ены|яются)|отсутствуют)';
        $exclusionAction = '(?:'.$singularExclusionAction.'|'.$pluralExclusionAction.')';
        $anyFixture = '(?<![\p{L}\p{N}])(?:'.implode('|', $fixturePatterns)
            .'|ванн(?:а|ы|у|е|ой|ою|ам|ами|ах)?|мойк\p{L}*|раковин\p{L}*|смесител\p{L}*|биде|писсуар\p{L}*|душ(?:а|у|е|ем|и|ей|ам|ами|ах)?)'
            .'(?![\p{L}\p{N}])';
        $separateAssertion = '(?<![\p{L}\p{N}])(?:предусмотр\p{L}*|требу\p{L}*|планиру\p{L}*|запланир\p{L}*|нуж\p{L}*|включ\p{L}*|будет|будут)(?![\p{L}\p{N}])';
        $negativePredicate = '(?:не\s+'.$negativeFixtureAction.'|'.$exclusionAction.')(?![\p{L}\p{N}])';
        $before = static fn (string $target, string $boundary, int $maxDistance = 60): string => '(?=[^,;.!?\r\n]{0,'.$maxDistance.'}'.$target.')'
            .'(?:(?!'.$boundary.')[^,;.!?\r\n])*?';
        $fixtureBoundary = $anyFixture.'|'.$separateAssertion;
        $excludedBoundary = $fixtureBoundary.'|(?<![\p{L}\p{N}])(?:установка|установку|монтаж|подключение)(?![\p{L}\p{N}])';
        $beforeNegative = $before($negativePredicate, $anyFixture);
        $beforeFixture = $before($fixture, $fixtureBoundary);
        $beforeNegatedFixture = $before($fixture, $fixtureBoundary.'|(?<![\p{L}\p{N}])(?:и|но|а|зато)(?![\p{L}\p{N}])');
        $beforeAnyFixture = $before($anyFixture, $fixtureBoundary);
        $beforeExcludedFixture = $before($fixture, $excludedBoundary);
        $beforeAnyExcludedFixture = $before($anyFixture, $excludedBoundary);
        $listConjunction = '(?:(?:(?:а|и)\s+)?(?:также|ещ[её])|и|плюс)(?![\p{L}\p{N}])';
        $numberModifier = '(?:од(?:ин|н(?:а|о|у|ой|ого|ому|им))|дв(?:а|е|ух|ум|умя)|тр(?:и|[её]х|[её]м|емя)|четыр(?:е|[её]х|[её]м|ьмя)|пят(?:ь|и|ью)|шест(?:ь|и|ью)|сем(?:ь|и|ью)|вос(?:емь|ьми|емью)|девят(?:ь|и|ью)|десят(?:ь|и|ью))';
        $modifierDefinition = '(?(DEFINE)(?<fixture_modifier>(?!'.$anyFixture.')'
            .'(?!не(?:предусмотр|запланир|нуж))'
            .'(?:[\p{L}-]{2,30}(?:ый|ий|ой|ая|яя|ое|ее|ые|ие|ого|его|ому|ему|ым|им|ом|ем|ую|юю|ых|их|ыми|ими)|[1-9]\d{0,2}|'.$numberModifier.')'
            .'(?![\p{L}\p{N}])))';
        $fixtureModifiers = '(?:(?&fixture_modifier)\s+){0,3}';
        $listConnector = '\s*(?:,\s*(?:'.$listConjunction.')?|'.$listConjunction.')\s*';
        $listSeparator = $listConnector.$fixtureModifiers;
        $attributeWord = '(?!'.$anyFixture.'|'.$listConjunction.'|(?<![\p{L}\p{N}])(?:не|без|кроме)(?![\p{L}\p{N}]))'
            .'(?:(?&fixture_modifier)|(?!'.$negativeAction.'|'.$exclusionAction.')[\p{L}\p{N}-]{1,32}(?![\p{L}\p{N}]))';
        $instrumentalFixture = '(?<![\p{L}\p{N}])(?:унитаз(?:ом|ами)|умывальник(?:ом|ами)|'
            .'душев(?:ой|ыми)\s+кабин(?:ой|ою|ами)|ванн(?:ой|ою|ами)|мойк(?:ой|ою|ами)|раковин(?:ой|ою|ами)|'
            .'смесител(?:ем|ями)|писсуар(?:ом|ами)|душ(?:ем|ами))(?![\p{L}\p{N}])';
        $fixtureListAttribute = $anyFixture.'\K\s+с(?:о)?\s+'.$attributeWord.'(?:(?:\s+|'.$listConnector.')'.$attributeWord.'){0,3}'
            .'(?='.$listSeparator.'(?!'.$instrumentalFixture.')'.$anyFixture.')';
        $fixtureList = $anyFixture.'(?:'.$listSeparator.$anyFixture.'){1,7}';
        $sharedNegative = '(?:'.$fixtureList.$beforeNegative.$negativePredicate.'|'
            .'(?<![\p{L}\p{N}])(?:без|кроме)(?![\p{L}\p{N}])'.$beforeAnyExcludedFixture.$fixtureList.'|'
            .'(?<![\p{L}\p{N}])(?:'.$exclusionAction.'|не\s+'.$negativeAction.')(?![\p{L}\p{N}])'
            .$beforeAnyFixture.$fixtureList.')';
        $negative = '(?<![\p{L}\p{N}])(?:'
            .'(?:без|кроме)(?![\p{L}\p{N}])'.$beforeExcludedFixture.$fixture.'|'
            .$exclusionAction.'(?![\p{L}\p{N}])'.$beforeFixture.$fixture.'|'
            .'не\s+'.$negativeAction.$beforeNegatedFixture.$fixture.'|'
            .$fixture.$beforeNegative.$negativePredicate
            .')(?![\p{L}\p{N}])';
        $countAction = '\d+(?:[.,]\d+)?\s*(?:шт|ед(?:иниц)?|компл)\.?';
        $requirementAction = '(?:требу(?:ется|ются)|планиру(?:ется|ются)|запланир\p{L}*|нуж(?:ен|на|но|ны))';
        $action = '(?:'
            .$installationAction.'|предусмотр\p{L}*|'.$requirementAction.'|'
            .'комплект\p{L}*|количеств\p{L}*|'.$countAction.')';
        $positiveFixture = '(?:'.$action.$before($anyFixture, $anyFixture, 80)
            .'(?:'.$anyFixture.$listSeparator.'){0,7}'.$fixture.'|'
            .$fixture.'(?:'.$listSeparator.$anyFixture.'){0,7}'.$before($action, $anyFixture, 80).$action.')';
        $clauseEnd = '(?:[,;.!?]|$)';
        $fullPredicate = '(?:'.$action.'|не\s+'.$negativeFixtureAction.'|'.$exclusionAction.')(?![\p{L}\p{N}])';
        $pluralPredicate = '(?:(?:не\s+)?(?:\p{L}*(?:ются|ятся|ены|аны|ны)|входят)|'.$pluralExclusionAction.')(?![\p{L}\p{N}])';
        $independentPredicate = '(?!'.$pluralPredicate.')'.$fullPredicate;
        $predicateBoundary = $anyFixture.'|'.$fullPredicate.'|(?<![\p{L}\p{N}])не(?![\p{L}\p{N}])';
        $independentFixtureAssertion = $fixtureModifiers.$anyFixture
            .$before($independentPredicate, $predicateBoundary, 80).$independentPredicate
            .$before($clauseEnd, $anyFixture, 80).$clauseEnd;
        $nominativeFixture = '(?<![\p{L}\p{N}])(?:унитаз|умывальник|душевая\s+кабина|ванна|мойка|раковина|смеситель|писсуар|душ|биде)(?![\p{L}\p{N}])';
        $independentConjoinedAssertion = $fixtureModifiers.$nominativeFixture
            .$before($independentPredicate, $predicateBoundary, 80).$independentPredicate
            .$before($clauseEnd, $anyFixture, 80).$clauseEnd;
        $statements = preg_split('~'.$modifierDefinition.'(?:[.!?;]+|\R+|,\s*(?=(?:'.$listConjunction.'\s*)?'.$independentFixtureAssertion.')'
            .'|\s+'.$listConjunction.'\s+(?='.$independentConjoinedAssertion.'))~iu', $text) ?: [];
        $pluralFixture = '(?<![\p{L}\p{N}])(?:унитаз(?:ы|ов|ам|ами|ах)|умывальник(?:и|ов|ам|ами|ах)|'
            .'душев(?:ые|ых|ым|ыми)\s+кабин(?:ы|ам|ами|ах)?|ванн(?:ы|ам|ами|ах)?|мойк(?:и|ам|ами|ах)|'
            .'раковин(?:ы|ам|ами|ах)?|смесител(?:и|ей|ям|ями|ях)|писсуар(?:ы|ов|ам|ами|ах)|душ(?:и|ей|ам|ами|ах)|биде)(?![\p{L}\p{N}])';
        $pluralCountModifier = '(?:[2-9]|[1-9]\d{1,2}|(?!од)'.$numberModifier.')(?![\p{L}\p{N}])';
        $countedFixture = $pluralCountModifier.'\s+'.$fixtureModifiers
            .'(?:унитаза|умывальника|смесителя|писсуара|душа)(?![\p{L}\p{N}])';
        $pluralFixtureHead = '(?!(?:(?&fixture_modifier)\s+){4})'.$fixtureModifiers.'(?:'.$pluralFixture.'|'.$countedFixture.')';
        $independentPluralAssertion = $pluralFixtureHead
            .$before($fullPredicate, $predicateBoundary, 80).$fullPredicate
            .$before($clauseEnd, $anyFixture, 80).$clauseEnd;
        $pluralStatementSeparator = '~'.$modifierDefinition.'(?:,\s*(?=(?:'.$listConjunction.'\s*)?'.$independentPluralAssertion.')'
            .'|\s+'.$listConjunction.'\s+(?='.$independentPluralAssertion.'))~iu';
        $priorAssertion = '~(?<![\p{L}\p{N}])(?:'.$action.'|без|кроме|нет|не|'.$exclusionAction.')(?![\p{L}\p{N}])~iu';
        $priorNegative = '~(?<![\p{L}\p{N}])(?:без|кроме|нет|не|'.$exclusionAction.')(?![\p{L}\p{N}])~iu';
        $preparedStatements = [];

        foreach ($statements as $statement) {
            $scanOffset = 0;
            while (preg_match($pluralStatementSeparator, $statement, $separatorMatch, PREG_OFFSET_CAPTURE, $scanOffset) === 1) {
                $separatorStart = $separatorMatch[0][1];
                $nextOffset = $separatorStart + strlen($separatorMatch[0][0]);
                $prefix = substr($statement, 0, $separatorStart);
                $assertionPattern = str_starts_with(ltrim($separatorMatch[0][0]), ',') ? $priorAssertion : $priorNegative;
                if (preg_match($assertionPattern, $prefix) !== 1) {
                    $scanOffset = $nextOffset;

                    continue;
                }
                $preparedStatements[] = $prefix;
                $statement = substr($statement, $nextOffset);
                $scanOffset = 0;
            }
            $preparedStatements[] = $statement;
        }

        foreach ($preparedStatements as $statement) {
            $withoutRoomPhrases = preg_replace(
                '~(?<![\p{L}\p{N}])(?:ванн|душев)(?:ая|ой|ую|ые|ых|ыми)\s+'
                .'(?:комнат\p{L}*|помещен\p{L}*)(?![\p{L}\p{N}])~iu',
                ' ',
                $statement
            ) ?? $statement;
            $withoutLocativeRoomReference = preg_replace(
                '~(?<![\p{L}\p{N}])(?:в|на|для)\s+ванн(?:ой|е|у|ы)(?![\p{L}\p{N}])~iu',
                ' ',
                $withoutRoomPhrases
            ) ?? $withoutRoomPhrases;
            $withoutFixtureListAttributes = preg_replace(
                '~'.$modifierDefinition.$fixtureListAttribute.'~iu',
                '',
                $withoutLocativeRoomReference
            ) ?? $withoutLocativeRoomReference;
            $withoutFixtureLocation = preg_replace(
                '~'.$modifierDefinition.'(?<![\p{L}\p{N}])(?:рядом\s+с|возле|около|вместо)\s+'.$fixtureModifiers.$anyFixture
                .'(?:'.$listSeparator.$anyFixture.'){0,7}~iu',
                ' ',
                $withoutFixtureListAttributes
            ) ?? $withoutFixtureListAttributes;
            $withoutSharedNegative = preg_replace('~'.$modifierDefinition.$sharedNegative.'~iu', ' ', $withoutFixtureLocation) ?? '';
            $positiveFixtureStatement = preg_replace('~'.$negative.'~iu', ' ', $withoutSharedNegative) ?? '';
            $positiveFixtureStatement = preg_replace('~(?<![\p{L}\p{N}])не\s+'.$negativeAction.'(?![\p{L}\p{N}])~iu', ' ', $positiveFixtureStatement) ?? '';

            if (preg_match(
                '~'.$modifierDefinition.'(?<![\p{L}\p{N}])'.$positiveFixture
                .'(?![\p{L}\p{N}])~iu',
                $positiveFixtureStatement
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $analysis */
    private function hasConfirmedSanitaryFixtureTakeoff(array $analysis, string $quantityKey): bool
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $takeoffs = is_array($documentContext['quantity_takeoffs'] ?? null) ? $documentContext['quantity_takeoffs'] : [];

        foreach ($takeoffs as $takeoff) {
            if (! is_array($takeoff) || EstimateGenerationQuantityKeyResolver::fromTakeoff($takeoff) !== $quantityKey) {
                continue;
            }

            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $sourceRefs = $this->sourceRefsFromEvidence($takeoff);
            $quantity = $this->firstNumeric($takeoff, ['quantity', 'value', 'value_number']);

            if (
                $quantity !== null
                && $quantity > 0
                && $sourceRefs !== []
                && ($payload['review_required'] ?? $takeoff['review_required'] ?? true) === false
            ) {
                return true;
            }
        }

        return false;
    }

    private function isConfirmedSanitaryFixtureInference(array $inference, string $quantityKey): bool
    {
        if (! $this->hasKnownInferenceTextProvenance($inference)) {
            return false;
        }
        $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];
        $quantity = $this->firstNumeric($payload, ['quantity_value', 'quantity', 'value', 'value_number']);

        return ($payload['quantity_key'] ?? null) === $quantityKey && $quantity !== null && $quantity > 0
            && ($payload['review_required'] ?? false) === false;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, string>
     */
    private function sanitaryFixtureEvidenceTexts(array $analysis): array
    {
        $fragments = [];
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];
        $manualDescription = $object['manual_description'] ?? null;

        if (is_string($manualDescription) && trim($manualDescription) !== '') {
            $fragments[] = $manualDescription;
        }

        $sourceDocuments = is_array($analysis['source_documents'] ?? null) ? $analysis['source_documents'] : [];
        foreach ($sourceDocuments as $document) {
            if (! is_array($document) || ! DocumentEvidencePolicy::isTrusted($document)) {
                continue;
            }

            $factsSummary = is_array($document['facts_summary'] ?? null) ? $document['facts_summary'] : [];
            $understanding = is_array($document['document_understanding'] ?? null)
                ? $document['document_understanding']
                : (is_array($factsSummary['document_understanding'] ?? null)
                    ? $factsSummary['document_understanding']
                    : []);
            $documentType = (string) ($understanding['document_type'] ?? '');
            $role = DocumentEvidencePolicy::roleForEstimation($document);
            $isQuantitySource = $role === 'quantity_source';
            $isProjectDocument = $role === 'context_document'
                && in_array($documentType, ['technical_document', 'project_document'], true);

            if (! $isQuantitySource && ! $isProjectDocument) {
                continue;
            }

            $text = $document['text'] ?? $document['extracted_text'] ?? null;
            if (is_string($text) && trim($text) !== '') {
                $fragments[] = $text;
            }
        }

        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        foreach ($documentContext['quantity_takeoffs'] ?? [] as $takeoff) {
            if (! is_array($takeoff) || ! $this->hasKnownTakeoffTextProvenance($takeoff)) {
                continue;
            }

            $fragments = [...$fragments, ...$this->textFields($takeoff)];
        }

        foreach ($documentContext['scope_inferences'] ?? [] as $inference) {
            if (! is_array($inference) || ! $this->hasKnownInferenceTextProvenance($inference)) {
                continue;
            }

            $fragments = [...$fragments, ...$this->textFields($inference)];
        }

        return $fragments;
    }

    /** @param array<string, mixed> $takeoff */
    private function hasKnownTakeoffTextProvenance(array $takeoff): bool
    {
        $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
        $sourceRefs = $this->sourceRefsFromEvidence($takeoff);
        $scopeKey = (string) ($takeoff['scope_key'] ?? '');
        $source = (string) ($payload['source'] ?? $takeoff['source'] ?? '');

        return $sourceRefs !== []
            && ($payload['review_required'] ?? $takeoff['review_required'] ?? true) === false
            && (
                in_array($scopeKey, ['specification_quantity', 'equipment_quantity', 'sanitary_fixture_count'], true)
                || in_array($source, [
                    'specification',
                    'specification_takeoff',
                    'work_volume_statement',
                    'work_volume_takeoff',
                    'project_document',
                ], true)
            );
    }

    /** @param array<string, mixed> $inference */
    private function hasKnownInferenceTextProvenance(array $inference): bool
    {
        $sourceRefs = $this->sourceRefsFromEvidence($inference);

        return $sourceRefs !== []
            && ($inference['review_required'] ?? true) === false
            && in_array((string) ($inference['inference_type'] ?? ''), [
                'specification_takeoff',
                'work_volume_takeoff',
                'project_requirement',
            ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    private function textFields(array $payload): array
    {
        $fragments = [];

        foreach (['name', 'label', 'title', 'description', 'formula', 'value_text'] as $field) {
            $value = $payload[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $fragments[] = $value;
            }
        }

        return $fragments;
    }

    /** @param array<string, bool> $sourceBackedQuantityKeys */
    private function definitionHasRequiredTakeoff(array $definition, array $sourceBackedQuantityKeys): bool
    {
        $quantityKey = (string) ($definition['quantity_key'] ?? '');
        if (! DirectTakeoffRequiredWorkItems::contains($quantityKey)) {
            return true;
        }

        if (isset($sourceBackedQuantityKeys[$quantityKey])) {
            return true;
        }

        $inference = is_array($definition['metadata']['scope_inference'] ?? null)
            ? $definition['metadata']['scope_inference']
            : [];
        $payload = is_array($inference['normalized_payload'] ?? null)
            ? $inference['normalized_payload']
            : [];

        return isset($payload['quantity_value'])
            && $this->sourceRefsFromEvidence($inference) !== []
            && ($inference['review_required'] ?? true) === false;
    }

    private function definitionMatchesObject(array $definition, array $analysis): bool
    {
        $quantityKey = (string) ($definition['quantity_key'] ?? '');

        return WorkItemObjectApplicabilityPolicy::allows($quantityKey, $analysis);
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @param  array<string, mixed>  $quantityModel
     * @return array<string, bool>
     */
    private function sourceBackedPackageQuantityKeys(array $definitions, array $quantityModel): array
    {
        $quantities = is_array($quantityModel['quantities'] ?? null) ? $quantityModel['quantities'] : [];
        $keys = [];

        foreach ($definitions as $definition) {
            $quantityKey = (string) ($definition['quantity_key'] ?? '');
            $quantity = is_array($quantities[$quantityKey] ?? null) ? $quantities[$quantityKey] : [];
            $sourceRefs = is_array($quantity['source_refs'] ?? null) ? $quantity['source_refs'] : [];

            if ($quantityKey !== '' && $sourceRefs !== [] && ($quantity['review_required'] ?? false) !== true) {
                $keys[$quantityKey] = true;
            }
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $quantityModel
     * @return array<int, array<string, mixed>>
     */
    private function packageDefinitions(
        string $packageKey,
        string $scopeType,
        array $quantityModel,
        array $analysis,
        bool $selectedResidentialEquipment,
    ): array {
        $roofType = $quantityModel['features']['roof_type'] ?? null;
        $definitionKey = $this->packageDefinitionKey($packageKey, $scopeType);

        if ($selectedResidentialEquipment) {
            return $this->residentialEquipmentDefinitions();
        }

        return match ($definitionKey) {
            'preconstruction', 'site_preparation' => [
                $this->definition('Подготовка строительной площадки', 'site', 'подготовка строительной площадки', 'site.setup'),
                $this->definition('Геодезическая разбивка осей', 'site', 'геодезическая разбивка осей здания', 'site.geodesy'),
            ],
            'earthworks' => [
                $this->definition('Разработка грунта под фундаменты', 'earthworks', 'разработка грунта в траншеях и котлованах', 'earth.trench'),
                $this->definition('Обратная засыпка пазух', 'earthworks', 'обратная засыпка грунта с уплотнением', 'earth.backfill'),
                $this->definition('Вывоз излишнего грунта', 'earthworks', 'погрузка и перевозка излишнего грунта', 'earth.export'),
                $this->definition('Планировка основания', 'earthworks', 'планировка основания под фундаменты', 'earth.plan'),
            ],
            'foundation', 'foundations' => [
                $this->definition('Песчано-щебеночная подготовка', 'foundation', 'устройство песчано-щебеночной подготовки', 'foundation.prep'),
                $this->definition('Опалубка фундаментов', 'foundation', 'устройство опалубки фундаментов', 'foundation.formwork'),
                $this->definition('Армирование фундаментов', 'foundation', 'армирование железобетонных фундаментов', 'foundation.rebar'),
                $this->definition('Бетонирование фундаментов', 'foundation', 'бетонирование железобетонных фундаментов', 'foundation.concrete'),
                $this->definition('Гидроизоляция фундаментов', 'foundation', 'гидроизоляция фундаментов', 'foundation.waterproofing'),
            ],
            'walls', 'office_partitions' => [
                $this->definition('Кладка наружных стен', 'walls', 'кладка наружных стен', 'walls.external_volume'),
                $this->definition('Устройство внутренних перегородок', 'walls', 'устройство внутренних перегородок', 'walls.internal'),
                $this->definition('Устройство перемычек', 'walls', 'устройство перемычек над проемами', 'walls.lintels'),
                $this->definition('Офисные перегородки', 'walls', 'монтаж офисных перегородок', 'office.partitions'),
            ],
            'slabs' => [
                $this->definition(
                    'Опалубка монолитного перекрытия',
                    'slabs',
                    'устройство опалубки монолитного железобетонного перекрытия',
                    'slabs.formwork'
                ),
                $this->definition(
                    'Бетонирование монолитного перекрытия',
                    'slabs',
                    'бетонирование монолитного железобетонного перекрытия жилого здания',
                    'slabs.concrete'
                ),
                $this->definition(
                    'Армирование монолитного перекрытия',
                    'slabs',
                    'армирование монолитного железобетонного перекрытия жилого здания',
                    'slabs.rebar'
                ),
            ],
            'industrial_floor' => [
                $this->definition('Устройство плиты пола', 'slabs', 'устройство железобетонной плиты пола', 'warehouse.floor_concrete'),
                $this->definition('Армирование плиты пола', 'slabs', 'армирование железобетонной плиты пола', 'warehouse.floor_rebar'),
                $this->definition('Топпинг промышленного пола', 'industrial_floor', 'упрочнение верхнего слоя промышленного пола', 'warehouse.floor_hardener'),
                $this->definition('Деформационные швы пола', 'industrial_floor', 'нарезка и герметизация деформационных швов', 'warehouse.floor_joints'),
            ],
            'stairs' => [
                $this->definition('Устройство лестничных маршей', 'stairs', 'устройство лестничных маршей', 'stairs.flights'),
                $this->definition('Устройство лестничных площадок', 'stairs', 'устройство лестничных площадок', 'stairs.landings'),
                $this->definition('Ограждение лестниц', 'stairs', 'устройство ограждений лестниц', 'stairs.railings'),
            ],
            'metal_frame' => [
                $this->definition('Монтаж металлических колонн', 'metal_frame', 'монтаж металлических колонн каркаса', 'warehouse.columns'),
                $this->definition('Монтаж балок и ферм', 'metal_frame', 'монтаж балок ферм и связей металлокаркаса', 'warehouse.beams'),
                $this->definition('Антикоррозионная защита металла', 'metal_frame', 'антикоррозионная защита металлических конструкций', 'warehouse.frame_weight'),
            ],
            'envelope', 'facade' => [
                $this->definition('Монтаж стеновых панелей', 'facade', 'монтаж стеновых сэндвич-панелей', 'warehouse.wall_panels'),
                $this->definition('Монтаж фасонных элементов', 'facade', 'монтаж доборных элементов фасада', 'warehouse.panel_flashings'),
                $this->definition('Отделка фасада', 'facade', 'отделка фасада здания', 'facade.area'),
            ],
            'roof' => $roofType === 'flat' ? [
                $this->definition('Устройство основания плоской кровли', 'roof', 'устройство основания плоской кровли', 'roof.flat_area', metadata: ['composition_work_key' => 'roof.flat.base']),
                $this->definition('Пароизоляция плоской кровли', 'roof', 'устройство пароизоляции плоской кровли', 'roof.flat_area', metadata: ['composition_work_key' => 'roof.flat.vapor_barrier']),
                $this->definition('Утепление плоской кровли', 'roof', 'утепление плоской кровли', 'roof.flat_area', metadata: ['composition_work_key' => 'roof.flat.insulation']),
                $this->definition('Гидроизоляционный ковер кровли', 'roof', 'устройство рулонной гидроизоляции кровли', 'roof.flat_area', metadata: ['composition_work_key' => 'roof.flat.waterproofing']),
                $this->definition('Водоотвод плоской кровли', 'roof', 'устройство внутреннего водостока кровли', 'roof.gutter'),
            ] : ($roofType === 'pitched' ? [
                $this->definition('Монтаж стропильной системы', 'roof', 'монтаж стропильной системы кровли', 'roof.rafters'),
                $this->definition('Пароизоляция скатной кровли', 'roof', 'устройство пароизоляции скатной кровли', 'roof.vapor_barrier'),
                $this->definition('Утепление кровли', 'roof', 'утепление скатной кровли', 'roof.area'),
                $this->definition('Подкровельная гидроизоляционная мембрана', 'roof', 'устройство подкровельной гидроизоляционной диффузионной мембраны', 'roof.membrane'),
                $this->definition('Деревянная обрешетка кровли', 'roof', 'устройство деревянной обрешетки с прозорами из брусков', 'roof.battens'),
                $this->definition('Монтаж кровельного покрытия', 'roof', 'монтаж кровельного покрытия', 'roof.area'),
                $this->definition('Водосточная система кровли', 'roof', 'монтаж водосточной системы кровли', 'roof.gutter'),
            ] : [
                $this->definition('Устройство кровельного покрытия', 'roof', 'устройство кровельного покрытия без уточнения конструкции кровли', 'roof.area'),
            ]),
            'openings', 'gates', 'entrance_group' => [
                $this->definition('Монтаж оконных блоков', 'openings', 'монтаж оконных блоков', 'openings.windows'),
                $this->definition('Монтаж дверных блоков', 'openings', 'монтаж дверных блоков', 'openings.doors'),
                $this->definition('Монтаж ворот', 'openings', 'монтаж промышленных ворот', 'warehouse.gates'),
                $this->definition('Погрузочные узлы', 'openings', 'устройство погрузочных узлов', 'warehouse.loading_nodes'),
            ],
            'electrical', 'power_supply' => [
                $this->definition('Прокладка магистральных кабелей', 'electrical', 'прокладка магистральных кабельных линий', 'electrical.main_cable'),
                $this->definition('Монтаж кабельных лотков', 'electrical', 'монтаж кабельных лотков', 'electrical.trays'),
                $this->definition('Прокладка силовых линий', 'electrical', 'прокладка силовых кабельных линий', 'electrical.power_lines'),
                $this->definition('Монтаж распределительного щита', 'electrical', 'монтаж квартирного распределительного щита', 'electrical.panel'),
                $this->definition('Монтаж розеток', 'electrical', 'установка штепсельных розеток', 'electrical.outlets'),
                $this->definition('Монтаж выключателей', 'electrical', 'установка выключателей освещения', 'electrical.switches'),
                $this->definition('Прокладка линий освещения', 'electrical', 'прокладка групповых линий освещения', 'lighting.lines'),
                $this->definition('Устройство заземления', 'electrical', 'устройство контура заземления', 'electrical.grounding'),
            ],
            'lighting' => [
                $this->definition('Прокладка линий освещения', 'electrical', 'прокладка групповых линий освещения', 'lighting.lines'),
                $this->definition('Монтаж светильников', 'electrical', 'установка светильников потолочных', 'lighting.fixtures'),
                $this->definition('Монтаж светильников', 'electrical', 'монтаж промышленных светильников', 'warehouse.lighting'),
            ],
            'low_current', 'server_room' => [
                $this->definition('Прокладка слаботочных трасс', 'electrical', 'прокладка слаботочных кабельных линий', 'warehouse.low_current'),
                $this->definition('Монтаж СКС', 'electrical', 'монтаж структурированной кабельной сети', 'office.network_points'),
                $this->definition('Серверная и связь', 'electrical', 'монтаж оборудования серверной', 'server.room'),
            ],
            'plumbing', 'water_supply', 'sanitary_rooms' => [
                $this->definition('Прокладка труб водоснабжения', 'plumbing', 'прокладка трубопроводов водоснабжения', 'plumbing.pipe'),
                $this->definition('Сантехнические точки', 'plumbing', 'подключение сантехнических приборов', 'sanitary.points'),
                $this->definition('Установка душевых кабин', 'plumbing', 'установка душевых кабин с пластиковым поддоном', 'sanitary.showers'),
                $this->definition('Установка унитазов', 'plumbing', 'установка унитазов с бачком непосредственно присоединенным', 'sanitary.toilets'),
                $this->definition('Установка умывальников', 'plumbing', 'установка одиночных умывальников с подводкой холодной и горячей воды', 'sanitary.washbasins'),
                $this->definition('Гидроизоляция мокрых зон', 'finishing', 'устройство гидроизоляции мокрых зон', 'sanitary.waterproofing', [
                    'Подготовка основания',
                    'Нанесение гидроизоляции',
                    'Герметизация примыканий',
                ]),
                $this->definition('Облицовка плиткой мокрых зон', 'finishing', 'облицовка плиткой мокрых зон', 'sanitary.tile', [
                    'Подготовка поверхности',
                    'Укладка плитки',
                    'Заполнение швов',
                ]),
                $this->definition('Устройство плиточного покрытия пола мокрых зон', 'finishing', 'устройство покрытий на цементном растворе из плиток керамических для полов одноцветных с красителем', 'sanitary.floor_tile', [
                    'Подготовка основания',
                    'Укладка напольной плитки',
                    'Заполнение швов',
                    'Очистка покрытия',
                ]),
            ],
            'water_sewerage' => [
                $this->definition('Прокладка труб водоснабжения', 'plumbing', 'прокладка трубопроводов водоснабжения', 'plumbing.pipe'),
                $this->definition('Сантехнические точки', 'plumbing', 'подключение сантехнических приборов', 'sanitary.points'),
                $this->definition('Установка душевых кабин', 'plumbing', 'установка душевых кабин с пластиковым поддоном', 'sanitary.showers'),
                $this->definition('Установка унитазов', 'plumbing', 'установка унитазов с бачком непосредственно присоединенным', 'sanitary.toilets'),
                $this->definition('Установка умывальников', 'plumbing', 'установка одиночных умывальников с подводкой холодной и горячей воды', 'sanitary.washbasins'),
                $this->definition('Прокладка внутренней канализации со стояками и ревизиями', 'sewerage', 'прокладка внутренних трубопроводов канализации из полипропиленовых труб диаметром 50 мм', 'sewerage.pipe', [
                    'Прокладка горизонтальных трубопроводов',
                    'Монтаж канализационных стояков',
                    'Установка фасонных частей и ревизий',
                    'Соединение внутренней канализационной сети',
                ]),
                $this->definition('Гидроизоляция мокрых зон', 'finishing', 'устройство гидроизоляции мокрых зон', 'sanitary.waterproofing', [
                    'Подготовка основания',
                    'Нанесение гидроизоляции',
                    'Герметизация примыканий',
                ]),
                $this->definition('Облицовка плиткой мокрых зон', 'finishing', 'облицовка плиткой мокрых зон', 'sanitary.tile', [
                    'Подготовка поверхности',
                    'Укладка плитки',
                    'Заполнение швов',
                ]),
                $this->definition('Устройство плиточного покрытия пола мокрых зон', 'finishing', 'устройство покрытий на цементном растворе из плиток керамических для полов одноцветных с красителем', 'sanitary.floor_tile', [
                    'Подготовка основания',
                    'Укладка напольной плитки',
                    'Заполнение швов',
                    'Очистка покрытия',
                ]),
            ],
            'sewerage' => [
                $this->definition('Прокладка внутренней канализации со стояками и ревизиями', 'sewerage', 'прокладка внутренних трубопроводов канализации из полипропиленовых труб диаметром 50 мм', 'sewerage.pipe', [
                    'Прокладка горизонтальных трубопроводов',
                    'Монтаж канализационных стояков',
                    'Установка фасонных частей и ревизий',
                    'Соединение внутренней канализационной сети',
                ]),
                $this->definition(
                    'Прокладка канализационного выпуска из полипропиленовых труб 110 мм',
                    'sewerage',
                    'прокладка внутренних трубопроводов канализации из полипропиленовых труб диаметром 110 мм',
                    'sewerage.outlet_route',
                ),
                $this->definition('Монтаж канализационных стояков', 'sewerage', 'монтаж стояков внутренней канализации', 'sewerage.risers'),
                $this->definition('Монтаж ревизий канализации', 'sewerage', 'монтаж ревизий и прочисток канализации', 'sewerage.revisions'),
            ],
            'heating' => [
                ...$this->electricBoilerDefinitions($analysis),
                $this->definition('Прокладка труб отопления', 'heating', 'прокладка трубопроводов отопления', 'heating.pipe'),
                $this->definition('Монтаж радиаторов', 'heating', 'монтаж отопительных приборов', 'heating.radiators'),
                $this->definition('Воздушно-тепловые завесы', 'heating', 'монтаж воздушно-тепловых завес', 'heating.air_curtains'),
            ],
            'ventilation' => [
                $this->definition('Монтаж вытяжных воздуховодов', 'ventilation', 'монтаж воздуховодов', 'ventilation.air_exchange'),
                $this->definition('Монтаж вентиляционных решеток и диффузоров', 'ventilation', 'монтаж вентиляционных решеток и диффузоров', 'ventilation.distribution_devices'),
                $this->definition('Воздухораспределители склада', 'ventilation', 'монтаж воздухораспределителей складской зоны', 'ventilation.warehouse_points'),
                $this->definition('Воздухораспределители офиса', 'ventilation', 'монтаж воздухораспределителей офисной зоны', 'ventilation.office_points'),
            ],
            'fire_safety' => [
                $this->definition('Пожарная сигнализация', 'engineering', 'монтаж пожарной сигнализации и оповещения', 'warehouse.fire'),
            ],
            'rough_finishing' => [
                $this->definition('Черновая подготовка пола', 'finishing', 'устройство черновой подготовки пола', 'rough.floor'),
                $this->definition('Черновая подготовка стен', 'finishing', 'подготовка поверхностей стен', 'rough.walls'),
                $this->definition('Черновая подготовка потолка', 'finishing', 'подготовка поверхности потолка под чистовую отделку', 'rough.ceiling'),
            ],
            'finish_finishing' => [
                $this->definition('Чистовое покрытие пола', 'finishing', 'устройство чистового покрытия пола', 'finish.floor'),
                $this->definition('Окраска стен', 'finishing', 'окраска стен', 'finish.paint'),
                $this->definition('Окраска потолка', 'finishing', 'окраска потолка водно-дисперсионными составами', 'finish.ceiling'),
                $this->definition('Монтаж плинтуса', 'finishing', 'монтаж плинтусов', 'finish.baseboard'),
                $this->definition('Подвесной потолок', 'finishing', 'монтаж подвесного потолка', 'office.ceiling'),
            ],
            'office_finishing' => [
                $this->definition('Черновая подготовка пола', 'finishing', 'устройство черновой подготовки пола', 'rough.floor'),
                $this->definition('Черновая подготовка стен', 'finishing', 'подготовка поверхностей стен', 'rough.walls'),
                $this->definition('Чистовое покрытие пола', 'finishing', 'устройство чистового покрытия пола', 'finish.floor'),
                $this->definition('Окраска стен', 'finishing', 'окраска стен', 'finish.paint'),
                $this->definition('Монтаж плинтуса', 'finishing', 'монтаж плинтусов', 'finish.baseboard'),
                $this->definition('Подвесной потолок', 'finishing', 'монтаж подвесного потолка', 'office.ceiling'),
            ],
            'process_pipelines' => [
                $this->industrialDefinition('Монтаж технологического трубопровода по спецификации', 'engineering', 'process_pipelines.length', 'm', 'installation'),
                $this->industrialDefinition('Испытания технологического трубопровода по программе', 'engineering', 'process_pipelines.testing_length', 'm', 'pipeline_testing'),
            ],
            'automation' => [
                $this->industrialDefinition('Монтаж приборов и средств автоматизации', 'electrical', 'automation.devices_count', 'count', 'installation'),
                $this->industrialDefinition('Проверка контуров автоматизации по программе', 'electrical', 'automation.loops_count', 'count', 'automation_testing'),
            ],
            'equipment' => [
                $this->industrialDefinition('Покупка оборудования по спецификации', 'engineering', 'equipment.units_count', 'count', 'equipment_purchase', 'equipment'),
                $this->industrialDefinition('Доставка оборудования по условиям поставщика', 'engineering', 'equipment.deliveries_count', 'count', 'delivery', 'other'),
                $this->industrialDefinition('Монтаж оборудования по требованиям изготовителя', 'engineering', 'equipment.units_count', 'count', 'installation'),
            ],
            'commissioning' => [
                $this->industrialDefinition('Пусконаладка оборудования по согласованной программе', 'engineering', 'commissioning.units_count', 'count', 'commissioning'),
            ],
            'external_networks', 'siteworks', 'roads' => [
                $this->definition('Наружные сети', 'site', 'устройство наружных инженерных сетей', 'networks.external'),
                $this->definition('Благоустройство территории', 'site', 'благоустройство территории', 'siteworks.area'),
                $this->definition('Дороги и площадки', 'site', 'устройство дорог и площадок', 'warehouse.roads'),
            ],
            default => [],
        };
    }

    private function residentialEquipmentDefinitions(): array
    {
        $definitions = [];
        foreach (['purchase' => ['equipment_purchase', 'equipment'], 'delivery' => ['delivery', 'other'], 'install' => ['installation', 'work']] as $key => [$requirement, $ordinaryType]) {
            $name = trans_message('estimate_generation.residential_equipment.'.$key.'.name');
            $definitions[] = [...$this->definition(
                $name, 'engineering', $name, 'equipment.'.$key,
                operations: [trans_message('estimate_generation.residential_equipment.'.$key.'.operation')],
                metadata: ['requirement_key' => $requirement, 'ordinary_item_type' => $ordinaryType,
                    'residential_equipment_candidate' => true,
                    'requires_specification' => true, 'requires_technology_confirmation' => true],
            ), 'unit' => 'count'];
        }

        return $definitions;
    }

    private function industrialDefinition(string $name, string $category, string $quantityKey, string $unit, string $requirement, string $ordinaryType = 'work'): array
    {
        return [...$this->definition($name, $category, $name, $quantityKey), 'unit' => $unit,
            'metadata' => ['requirement_key' => $requirement, 'ordinary_item_type' => $ordinaryType,
                'requires_specification' => true, 'requires_technology_confirmation' => true]];
    }

    /** @param array<string, mixed> $analysis @return list<array<string, mixed>> */
    private function electricBoilerDefinitions(array $analysis): array
    {
        $decision = $this->residentialScopeDecision($analysis, 'heating_source');

        if (($decision['option'] ?? null) !== 'electric_boiler'
            || ! in_array($decision['status'] ?? null, ['preliminary', 'documented', 'user_declared'], true)) {
            return [];
        }

        return [$this->definition(
            'Монтаж электрического котла отопления до 30 кг',
            'heating',
            'монтаж оборудования в помещении массой до 0,03 т электрический котел отопления',
            'heating.unit',
            metadata: [
                'norm_basis' => 'analog',
                'analog_reason' => 'electric_boiler_installation_by_equipment_mass',
                'equipment_mass_band' => 'up_to_0_03_t',
                'scope_decision' => $decision,
            ],
        )];
    }

    /** @param array<string, mixed> $analysis @return array<string, mixed> */
    private function residentialScopeDecision(array $analysis, string $key): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $decisions = $analysis['residential_scope_decisions']
            ?? $documentContext['residential_scope_decisions']
            ?? [];

        if (! is_array($decisions)) {
            return [];
        }
        if (isset($decisions[$key]) && is_array($decisions[$key])) {
            $decision = $decisions[$key];
            $decision['key'] ??= $key;

            return $decision;
        }

        foreach ($decisions as $decision) {
            if (is_array($decision) && ($decision['key'] ?? null) === $key) {
                return $decision;
            }
        }

        return [];
    }

    private function packageDefinitionKey(string $packageKey, string $scopeType): string
    {
        if ($scopeType !== '' && ($packageKey === '' || str_starts_with($packageKey, 'local-'))) {
            return $scopeType;
        }

        return $packageKey;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param  array<string, bool>  $sourceBackedPackageQuantityKeys
     */
    private function scopeInferenceDefinitions(
        array $analysis,
        string $scopeType,
        string $packageKey,
        array $sourceBackedPackageQuantityKeys = []
    ): array {
        $definitions = [];
        $isUnmappedReviewPackage = $packageKey === 'unmapped_quantity_rows';

        foreach ($this->scopeInferenceService->inferFromAnalysis($analysis) as $inference) {
            $inferenceScope = (string) ($inference['scope_type'] ?? '');
            $inferenceType = (string) ($inference['inference_type'] ?? '');
            $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];
            $quantityKey = (string) ($payload['quantity_key'] ?? '');
            $isUnmappedQuantity = $inferenceType === 'unmapped_quantity_row' || str_starts_with($quantityKey, 'unmapped.');

            if ($quantityKey === '' || ! $this->scopeCompatible($inferenceScope, $scopeType)) {
                continue;
            }

            if ($isUnmappedReviewPackage !== $isUnmappedQuantity) {
                continue;
            }

            if (! $isUnmappedQuantity && isset($sourceBackedPackageQuantityKeys[$quantityKey])) {
                continue;
            }

            $definitions[] = $this->definition(
                (string) ($inference['title'] ?? $this->titleForScope($inferenceScope)),
                $inferenceScope,
                (string) ($inference['title'] ?? $this->titleForScope($inferenceScope)),
                $quantityKey,
                confidence: (float) ($inference['confidence'] ?? 0.74),
                metadata: [
                    'generation_source' => 'scope_inference',
                    'scope_inference' => $inference,
                ]
            );
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $quantityModel
     * @return array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool, source: string, learning_hint?: array<string, mixed>}
     */
    private function quantityForDefinition(array $definition, array $analysis, array $quantityModel): array
    {
        $quantityKey = (string) $definition['quantity_key'];
        $quantities = is_array($quantityModel['quantities'] ?? null) ? $quantityModel['quantities'] : [];

        if (is_array($quantities[$quantityKey] ?? null)) {
            $quantity = $quantities[$quantityKey];
            $result = [
                'value' => (float) $quantities[$quantityKey]['value'],
                'unit' => (string) $quantities[$quantityKey]['unit'],
                'basis' => (string) $quantities[$quantityKey]['basis'],
                'confidence' => (float) ($quantities[$quantityKey]['confidence'] ?? 0.72),
                'source_refs' => is_array($quantities[$quantityKey]['source_refs'] ?? null)
                    ? $this->normalizeSourceRefs($quantities[$quantityKey]['source_refs'])
                    : $this->sourceRefsForQuantityKey($analysis, $quantityKey),
                'review_required' => (bool) ($quantities[$quantityKey]['review_required'] ?? false),
                'source' => (string) ($quantities[$quantityKey]['source'] ?? 'document_quantity'),
            ];

            if (is_array($quantity['learning_hint'] ?? null)) {
                $result['learning_hint'] = $this->quantityLearningHintForMetadata($quantity['learning_hint']);
            }

            return $result;
        }

        $inference = is_array($definition['metadata']['scope_inference'] ?? null) ? $definition['metadata']['scope_inference'] : [];
        $payload = is_array($inference['normalized_payload'] ?? null) ? $inference['normalized_payload'] : [];

        if (isset($payload['quantity_value'])) {
            $sourceRefs = $this->sourceRefsFromEvidence($inference);

            return [
                'value' => (float) $payload['quantity_value'],
                'unit' => (string) ($payload['unit'] ?? 'ед'),
                'basis' => 'Количество извлечено из чертежа и требует сметной проверки.',
                'confidence' => (float) ($inference['confidence'] ?? 0.74),
                'source_refs' => $sourceRefs,
                'review_required' => $sourceRefs === [] || (bool) ($inference['review_required'] ?? true),
                'source' => (string) ($payload['source'] ?? $inference['inference_type'] ?? 'scope_inference'),
            ];
        }

        return [
            'value' => 1.0,
            'unit' => 'компл',
            'basis' => 'Количество не найдено в документации и требует проверки сметчиком.',
            'confidence' => 0.48,
            'source_refs' => [],
            'review_required' => true,
            'source' => 'planner_fallback',
        ];
    }

    /**
     * @param  array<string, mixed>  $quantity
     */
    private function isPlannerFallbackQuantity(array $quantity): bool
    {
        return
            ($quantity['source'] ?? null) === 'planner_fallback'
            && ($quantity['source_refs'] ?? []) === [];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array{quantities: array<string, array<string, mixed>>, explicit_quantity_keys: array<string, true>, features: array<string, mixed>}
     */
    private function documentQuantityModel(array $analysis): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $quantities = $this->quantitiesFromFactsSummary($documentContext);
        $explicitQuantityKeys = [];

        foreach ($this->quantitiesFromTakeoffs($documentContext) as $key => $quantity) {
            $quantities[$key] = $quantity;
            if (($quantity['source_refs'] ?? []) !== []) {
                $explicitQuantityKeys[$key] = true;
            }
        }

        foreach ($this->quantitiesFromCanonicalBuildingQuantities($documentContext) as $key => $quantity) {
            $quantities[$key] = $quantity;
            $explicitQuantityKeys[$key] = true;
        }

        $quantities = $this->withQuantityLearningHints($quantities, $documentContext);

        return [
            'quantities' => $quantities,
            'explicit_quantity_keys' => $explicitQuantityKeys,
            'features' => [
                'roof_type' => $this->roofTypeFromDocumentContext($analysis, $documentContext),
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function quantitiesFromCanonicalBuildingQuantities(array $documentContext): array
    {
        $rows = is_array($documentContext['canonical_building_quantities'] ?? null)
            ? $documentContext['canonical_building_quantities']
            : [];
        $quantities = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            try {
                $quantity = QuantityData::fromArray($row);
            } catch (Throwable) {
                continue;
            }

            if ($quantity->evidenceIds === [] || (float) $quantity->amount <= 0) {
                continue;
            }

            if ($quantity->source === QuantitySource::Estimated
                && $quantity->reviewBlockers === []) {
                continue;
            }
            if (DirectTakeoffRequiredWorkItems::contains($quantity->key)
                && $quantity->source !== QuantitySource::Evidenced) {
                continue;
            }

            $quantities[$quantity->key] = [
                'value' => (float) $quantity->amount,
                'unit' => $quantity->unit,
                'basis' => $quantity->formulaKey,
                'confidence' => (float) ($quantity->formulaInputs['scenario']['confidence'] ?? 0.9),
                'source_refs' => array_map(
                    static fn (string $evidenceId): array => ['evidence_id' => $evidenceId],
                    $quantity->evidenceIds,
                ),
                'review_required' => $quantity->reviewBlockers !== [],
                'source' => 'canonical_building_quantity',
            ];
        }

        return $quantities;
    }

    private function plannedQuantityConfidence(array $definition, array $quantity, float $default): float
    {
        $definitionConfidence = (float) ($definition['confidence'] ?? $quantity['confidence'] ?? $default);

        return $definitionConfidence;
    }

    /**
     * @param  array<string, mixed>  $documentContext
     * @return array<string, array<string, mixed>>
     */
    private function quantitiesFromTakeoffs(array $documentContext): array
    {
        $takeoffs = is_array($documentContext['quantity_takeoffs'] ?? null) ? $documentContext['quantity_takeoffs'] : [];
        $quantities = [];
        $hasFloorAreaAggregate = $this->hasTakeoffScope($takeoffs, ['floor_finish_area', 'rough_floor_area']);

        foreach ($takeoffs as $takeoff) {
            if (! is_array($takeoff)) {
                continue;
            }

            $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];
            $scopeKey = (string) ($takeoff['scope_key'] ?? '');

            if ($scopeKey === 'room_area' && $hasFloorAreaAggregate) {
                continue;
            }

            $quantityKey = EstimateGenerationQuantityKeyResolver::fromTakeoff($takeoff);
            $value = $this->firstNumeric($takeoff, ['quantity', 'value', 'value_number']);

            if ($quantityKey === '' || $value === null || $value <= 0) {
                continue;
            }

            $quantity = $this->modelQuantity(
                value: $value,
                unit: (string) ($takeoff['unit'] ?? $payload['unit'] ?? 'ед'),
                basis: (string) ($takeoff['name'] ?? $takeoff['label'] ?? $takeoff['formula'] ?? 'Количество извлечено из проектной документации.'),
                confidence: (float) ($takeoff['confidence'] ?? 0.76),
                sourceRefs: $this->sourceRefsFromEvidence($takeoff),
                reviewRequired: (bool) ($payload['review_required'] ?? $takeoff['review_required'] ?? false)
            );
            $quantities[$quantityKey] = isset($quantities[$quantityKey])
                ? $this->mergeModelQuantities($quantities[$quantityKey], $quantity)
                : $quantity;
        }

        return $quantities;
    }

    /**
     * @param  array<string, array<string, mixed>>  $quantities
     * @param  array<string, mixed>  $documentContext
     * @return array<string, array<string, mixed>>
     */
    private function withQuantityLearningHints(array $quantities, array $documentContext): array
    {
        $hints = is_array($documentContext['quantity_learning_hints'] ?? null)
            ? $documentContext['quantity_learning_hints']
            : [];

        foreach ($quantities as $quantityKey => $quantity) {
            $hint = is_array($hints[$quantityKey] ?? null) ? $hints[$quantityKey] : null;

            if ($hint === null) {
                continue;
            }

            $quantity['learning_hint'] = $this->quantityLearningHintForMetadata($hint);

            if ($this->hasQuantityLearningConflict($quantity, $hint)) {
                $quantity['review_required'] = true;
                $quantity['confidence'] = round(min((float) ($quantity['confidence'] ?? 0.72), 0.6), 4);
                $quantity['basis'] = $this->basisWithLearningConflict((string) ($quantity['basis'] ?? ''));
                $quantity['source'] = 'document_quantity_learning_conflict';
            }

            $quantities[$quantityKey] = $quantity;
        }

        return $quantities;
    }

    /**
     * @param  array<string, mixed>  $quantity
     * @param  array<string, mixed>  $hint
     */
    private function hasQuantityLearningConflict(array $quantity, array $hint): bool
    {
        $currentQuantity = $this->numericValue($quantity['value'] ?? null);
        $learnedQuantity = $this->numericValue($hint['quantity'] ?? null);

        if ($currentQuantity === null || $learnedQuantity === null || $currentQuantity <= 0 || $learnedQuantity <= 0) {
            return false;
        }

        $currentUnit = (string) ($quantity['unit'] ?? '');
        $learnedUnit = (string) ($hint['unit'] ?? '');

        if ($currentUnit === '' || $learnedUnit === '' || ! NormativeUnitNormalizer::compatible($currentUnit, $learnedUnit)) {
            return false;
        }

        $absoluteDiff = abs($currentQuantity - $learnedQuantity);
        $relativeDiff = $absoluteDiff / max($learnedQuantity, 0.01);

        return $absoluteDiff > 0.5 && $relativeDiff > 0.25;
    }

    private function basisWithLearningConflict(string $basis): string
    {
        $message = $this->estimateGenerationMessage(
            'quantity_learning_conflict_basis',
            'Объем отличается от ранее подтвержденного похожего значения; требуется проверка.'
        );

        return trim(implode('; ', array_values(array_filter([$basis, $message]))));
    }

    private function estimateGenerationMessage(string $key, string $fallback): string
    {
        try {
            if (function_exists('app') && app()->bound('translator') && function_exists('trans_message')) {
                return trans_message('estimate_generation.'.$key);
            }
        } catch (Throwable) {
            return $fallback;
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $quantity
     * @return array<string, mixed>
     */
    private function quantityLearningMetadata(array $quantity): array
    {
        if (! is_array($quantity['learning_hint'] ?? null)) {
            return [];
        }

        return [
            'quantity_learning_hint' => $this->quantityLearningHintForMetadata($quantity['learning_hint']),
        ];
    }

    /**
     * @param  array<string, mixed>  $hint
     * @return array<string, mixed>
     */
    private function quantityLearningHintForMetadata(array $hint): array
    {
        return array_filter([
            'quantity_key' => isset($hint['quantity_key']) ? (string) $hint['quantity_key'] : null,
            'learning_example_id' => isset($hint['learning_example_id']) ? (int) $hint['learning_example_id'] : null,
            'quantity' => $this->numericValue($hint['quantity'] ?? null),
            'unit' => isset($hint['unit']) ? (string) $hint['unit'] : null,
            'quantity_basis' => isset($hint['quantity_basis']) ? (string) $hint['quantity_basis'] : null,
            'calculation_basis' => isset($hint['calculation_basis']) ? (string) $hint['calculation_basis'] : null,
            'source_quality_score' => $this->numericValue($hint['source_quality_score'] ?? null),
            'confidence' => $this->numericValue($hint['confidence'] ?? null),
            'same_project' => (bool) ($hint['same_project'] ?? false),
            'accepted_at' => isset($hint['accepted_at']) ? (string) $hint['accepted_at'] : null,
            'examples_count' => isset($hint['examples_count']) ? (int) $hint['examples_count'] : null,
            'source_refs' => is_array($hint['source_refs'] ?? null) ? $this->normalizeSourceRefs($hint['source_refs']) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, mixed>  $takeoffs
     * @param  array<int, string>  $scopeKeys
     */
    private function hasTakeoffScope(array $takeoffs, array $scopeKeys): bool
    {
        foreach ($takeoffs as $takeoff) {
            if (is_array($takeoff) && in_array((string) ($takeoff['scope_key'] ?? ''), $scopeKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $documentContext
     * @return array<string, array<string, mixed>>
     */
    private function quantitiesFromFactsSummary(array $documentContext): array
    {
        $factsSummary = is_array($documentContext['facts_summary'] ?? null) ? $documentContext['facts_summary'] : [];
        $documentSourceRefs = is_array($documentContext['source_refs'] ?? null)
            ? $this->normalizeSourceRefs($documentContext['source_refs'])
            : [];
        $quantities = [];
        $area = $this->numericValue($factsSummary['total_area_m2'] ?? null);

        if ($area !== null && $area > 0) {
            foreach (['rough.floor', 'finish.floor'] as $quantityKey) {
                $quantities[$quantityKey] = $this->modelQuantity(
                    $area,
                    'м2',
                    'Площадь объекта извлечена из проектной документации.',
                    0.72,
                    $documentSourceRefs,
                    $documentSourceRefs === [],
                    'facts_summary_area'
                );
            }
        }

        foreach (($factsSummary['zones'] ?? []) as $zone) {
            if (! is_array($zone)) {
                continue;
            }

            $zoneArea = $this->numericValue($zone['area_m2'] ?? null);
            $scopeKey = (string) ($zone['scope_key'] ?? '');

            if ($zoneArea === null || $zoneArea <= 0) {
                continue;
            }

            $sourceRefs = isset($zone['source_ref']) && is_array($zone['source_ref']) ? [$zone['source_ref']] : [];

            foreach ($this->zoneQuantityKeys($scopeKey) as $quantityKey) {
                $quantities[$quantityKey] = $this->modelQuantity(
                    $zoneArea,
                    'м2',
                    (string) ($zone['label'] ?? 'Площадь зоны извлечена из проектной документации.'),
                    (float) ($zone['confidence'] ?? 0.72),
                    $sourceRefs,
                    $sourceRefs === [],
                    'facts_summary_zone'
                );
            }
        }

        return $quantities;
    }

    /**
     * @return array<int, string>
     */
    private function zoneQuantityKeys(string $scopeKey): array
    {
        return match ($scopeKey) {
            'warehouse_area', 'warehouse', 'industrial_floor' => [
                'warehouse.floor',
                'warehouse.floor_hardener',
                'warehouse.wall_panels',
            ],
            'office_area', 'office' => [
                'office.floor',
                'office.ceiling',
                'office.floor_finish',
            ],
            'room_area', 'finishing' => [
                'rough.floor',
                'finish.floor',
            ],
            default => [],
        };
    }

    /**
     * @param  array<int, mixed>  $sourceRefs
     * @return array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool, source: string}
     */
    private function modelQuantity(
        float $value,
        string $unit,
        string $basis,
        float $confidence,
        array $sourceRefs,
        bool $reviewRequired = false,
        string $source = 'document_quantity'
    ): array {
        return [
            'value' => round($value, 4),
            'unit' => $unit,
            'basis' => $basis,
            'confidence' => round(max(min($confidence, 0.98), 0.35), 4),
            'source_refs' => $this->normalizeSourceRefs($sourceRefs),
            'review_required' => $reviewRequired,
            'source' => $source,
        ];
    }

    private function canonicalPlannedQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format(max($quantity, 0.01), 4, '.', ''), '0'), '.');
    }

    /**
     * @param  array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool, source: string}  $left
     * @param  array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool, source: string}  $right
     * @return array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool, source: string}
     */
    private function mergeModelQuantities(array $left, array $right): array
    {
        $leftUnit = mb_strtolower(trim((string) ($left['unit'] ?? '')));
        $rightUnit = mb_strtolower(trim((string) ($right['unit'] ?? '')));
        $sourceRefs = $this->normalizeSourceRefs([
            ...($left['source_refs'] ?? []),
            ...($right['source_refs'] ?? []),
        ]);
        $basis = implode('; ', array_values(array_filter([
            (string) ($left['basis'] ?? ''),
            (string) ($right['basis'] ?? ''),
        ])));

        if ($leftUnit !== $rightUnit) {
            return [
                'value' => (float) ($left['value'] ?? 0),
                'unit' => (string) ($left['unit'] ?? ''),
                'basis' => trim($basis.'; единицы измерения извлеченных объемов различаются и требуют проверки.'),
                'confidence' => round(min((float) ($left['confidence'] ?? 0.5), (float) ($right['confidence'] ?? 0.5), 0.5), 4),
                'source_refs' => $sourceRefs,
                'review_required' => true,
                'source' => 'document_quantity_conflict',
            ];
        }

        if ($this->isDuplicateFactsSummaryArea($left, $right)) {
            return $this->sourceBackedQuantity($left, $right);
        }

        return [
            'value' => round((float) ($left['value'] ?? 0) + (float) ($right['value'] ?? 0), 4),
            'unit' => (string) ($left['unit'] ?? $right['unit'] ?? ''),
            'basis' => $basis,
            'confidence' => round(min((float) ($left['confidence'] ?? 0.76), (float) ($right['confidence'] ?? 0.76)), 4),
            'source_refs' => $sourceRefs,
            'review_required' => (bool) ($left['review_required'] ?? false) || (bool) ($right['review_required'] ?? false),
            'source' => ($left['source'] ?? null) === ($right['source'] ?? null)
                ? (string) ($left['source'] ?? 'document_quantity')
                : 'document_quantity_aggregate',
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function isDuplicateFactsSummaryArea(array $left, array $right): bool
    {
        $leftValue = (float) ($left['value'] ?? 0);
        $rightValue = (float) ($right['value'] ?? 0);

        if (abs($leftValue - $rightValue) > 0.01) {
            return false;
        }

        return ($this->isFactsSummaryAreaQuantity($left) && $this->hasSourceBackedQuantity($right))
            || ($this->isFactsSummaryAreaQuantity($right) && $this->hasSourceBackedQuantity($left));
    }

    /**
     * @param  array<string, mixed>  $quantity
     */
    private function isFactsSummaryAreaQuantity(array $quantity): bool
    {
        return ($quantity['source'] ?? null) === 'facts_summary_area';
    }

    /**
     * @param  array<string, mixed>  $quantity
     */
    private function hasSourceBackedQuantity(array $quantity): bool
    {
        return ! $this->isFactsSummaryAreaQuantity($quantity)
            && ($quantity['source_refs'] ?? []) !== [];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{value: float, unit: string, basis: string, confidence: float, source_refs: array<int, array<string, mixed>>, review_required: bool, source: string}
     */
    private function sourceBackedQuantity(array $left, array $right): array
    {
        $quantity = $this->hasSourceBackedQuantity($left) ? $left : $right;

        return [
            'value' => (float) ($quantity['value'] ?? 0),
            'unit' => (string) ($quantity['unit'] ?? ''),
            'basis' => (string) ($quantity['basis'] ?? ''),
            'confidence' => (float) ($quantity['confidence'] ?? 0.76),
            'source_refs' => $this->normalizeSourceRefs($quantity['source_refs'] ?? []),
            'review_required' => (bool) ($quantity['review_required'] ?? false),
            'source' => (string) ($quantity['source'] ?? 'document_quantity'),
        ];
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $documentContext
     */
    private function roofTypeFromDocumentContext(array $analysis, array $documentContext): ?string
    {
        return $this->roofTypeResolver->resolve([
            ...$analysis,
            'document_context' => $documentContext,
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $documentContext
     * @return array<int, string>
     */
    private function documentTextFragments(array $analysis, array $documentContext): array
    {
        $fragments = [];
        $object = is_array($analysis['object'] ?? null) ? $analysis['object'] : [];

        foreach ([$object['description'] ?? null, $object['building_type'] ?? null, $object['object_type'] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $fragments[] = $value;
            }
        }

        foreach (['facts', 'scope_inferences', 'drawing_elements'] as $collectionKey) {
            $items = is_array($documentContext[$collectionKey] ?? null) ? $documentContext[$collectionKey] : [];

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (['label', 'title', 'description', 'value_text', 'name', 'type'] as $field) {
                    if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') {
                        $fragments[] = $item[$field];
                    }
                }
            }
        }

        return $fragments;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstNumeric(array $payload, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $this->numericValue($payload[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function numericValue(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric(str_replace(',', '.', $value))) {
            return (float) str_replace(',', '.', $value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<int, array<string, mixed>>
     */
    private function sourceRefsForQuantityKey(array $analysis, string $quantityKey): array
    {
        $documentContext = is_array($analysis['document_context'] ?? null) ? $analysis['document_context'] : [];
        $takeoffs = is_array($documentContext['quantity_takeoffs'] ?? null) ? $documentContext['quantity_takeoffs'] : [];
        $refs = [];

        foreach ($takeoffs as $takeoff) {
            if (! is_array($takeoff)) {
                continue;
            }

            $takeoffKey = EstimateGenerationQuantityKeyResolver::fromTakeoff($takeoff);

            if ($takeoffKey === $quantityKey && is_array($takeoff['source_refs'] ?? null)) {
                foreach ($takeoff['source_refs'] as $sourceRef) {
                    if (is_array($sourceRef)) {
                        $refs[] = $sourceRef;
                    }
                }
            }
        }

        return $refs;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array<string, mixed>>
     */
    private function uniquePricedItems(array $items): array
    {
        $unique = [];
        $references = [];
        $referenceGroups = [];

        foreach ($items as $item) {
            $semanticIdentity = (string) (
                $item['metadata']['material_scenario_work_key']
                ?? $item['metadata']['composition_work_key']
                ?? $item['quantity_formula']
                ?? $item['key']
            );
            $normativeIdentity = (string) (
                $item['normative_rate_code']
                ?? $item['normative_search_key']
                ?? $item['key']
            );
            $key = $normativeIdentity.'|'.$semanticIdentity;
            if (($item['metadata']['generation_source'] ?? null) === 'project_document_normative_reference') {
                $referenceGroups[$key][] = count($references);
                $references[] = $item;

                continue;
            }
            $unique[$key] = $item;
        }

        foreach ($referenceGroups as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            foreach ($indexes as $index) {
                // One norm does not prove that two source rows describe the same physical work.
                $references[$index]['item_type'] = 'quantity_review';
                $references[$index]['pricing_status'] = 'not_applicable';
                $references[$index]['pricing_blocker'] = 'quantity_review_required';
                $references[$index]['validation_flags'] = array_values(array_unique([
                    ...($references[$index]['validation_flags'] ?? []), 'quantity_review_required',
                ]));
                $references[$index]['metadata']['display_role'] = 'quantity_review';
                $references[$index]['metadata']['normative_grounding_policy'] = 'quantity_confirmation_required';
            }
        }

        return [...$references, ...array_values($unique)];
    }

    /**
     * @param  array<int, mixed>  $sourceRefs
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSourceRefs(array $sourceRefs): array
    {
        return array_values(array_filter($sourceRefs, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function sourceRefsFromEvidence(array $evidence): array
    {
        $sourceRefs = is_array($evidence['source_refs'] ?? null)
            ? $this->normalizeSourceRefs($evidence['source_refs'])
            : [];

        if ($sourceRefs !== []) {
            return $sourceRefs;
        }

        return isset($evidence['source_ref']) && is_array($evidence['source_ref']) && $evidence['source_ref'] !== []
            ? $this->normalizeSourceRefs([$evidence['source_ref']])
            : [];
    }

    private function scopeCompatible(string $inferenceScope, string $targetScope): bool
    {
        if ($targetScope === '' || $inferenceScope === $targetScope) {
            return true;
        }

        return match ($targetScope) {
            'engineering' => in_array($inferenceScope, ['electrical', 'plumbing', 'heating', 'ventilation'], true),
            'finishing' => in_array($inferenceScope, ['finishing', 'openings'], true),
            default => false,
        };
    }

    /**
     * @param  array<int, string>  $operations
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function definition(
        string $name,
        string $category,
        string $searchText,
        string $quantityKey,
        array $operations = [],
        float $confidence = 0.7,
        array $metadata = []
    ): array {
        return [
            'name' => $name,
            'category' => $category,
            'normative_search_text' => $searchText,
            'quantity_key' => $quantityKey,
            'operations' => $operations !== [] ? $operations : $this->operationBank($category),
            'confidence' => $confidence,
            'metadata' => ['composition_work_key' => $quantityKey, ...$metadata],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function operationBank(string $category): array
    {
        return match ($category) {
            'site' => ['Разметка территории', 'Подготовка основания', 'Устройство временных элементов', 'Планировка поверхности', 'Проверка отметок'],
            'earthworks' => ['Разметка участка', 'Разработка грунта', 'Погрузка грунта', 'Вывоз грунта', 'Уплотнение основания'],
            'foundation' => ['Подготовка основания', 'Монтаж опалубки', 'Вязка арматуры', 'Укладка бетонной смеси', 'Уход за бетоном'],
            'walls' => ['Разметка осей', 'Подача материалов', 'Кладка конструкций', 'Армирование рядов', 'Контроль плоскости'],
            'slabs', 'industrial_floor' => ['Подготовка основания', 'Монтаж арматуры', 'Укладка бетонной смеси', 'Выравнивание поверхности', 'Устройство швов'],
            'stairs' => ['Разметка лестничного узла', 'Устройство основания', 'Монтаж несущих элементов', 'Устройство ограждений', 'Проверка геометрии'],
            'metal_frame' => ['Разметка осей', 'Монтаж элементов', 'Болтовые соединения', 'Выверка геометрии', 'Защитное покрытие'],
            'roof' => ['Подготовка основания', 'Устройство изоляции', 'Монтаж покрытия', 'Устройство примыканий', 'Контроль герметичности'],
            'facade' => ['Подготовка основания', 'Монтаж подсистемы', 'Монтаж облицовки', 'Устройство примыканий', 'Контроль креплений'],
            'openings' => ['Подготовка проема', 'Монтаж блока', 'Крепление', 'Герметизация примыканий', 'Регулировка'],
            'electrical' => ['Разметка трасс', 'Прокладка линий', 'Монтаж креплений', 'Подключение', 'Измерения'],
            'plumbing', 'sewerage', 'heating' => ['Разметка трасс', 'Прокладка труб', 'Монтаж арматуры', 'Крепление', 'Испытания'],
            'ventilation' => ['Разметка трасс', 'Монтаж воздуховодов', 'Монтаж решеток', 'Крепление', 'Пусковая проверка'],
            'engineering' => ['Разметка трасс и зон установки', 'Монтаж оборудования', 'Прокладка линий', 'Подключение системы', 'Проверка работоспособности'],
            'finishing' => ['Подготовка поверхности', 'Грунтование', 'Основной слой', 'Выравнивание', 'Финишный контроль'],
            'temporary' => ['Разметка временной зоны', 'Установка опор', 'Монтаж ограждения', 'Крепление секций', 'Проверка устойчивости'],
            default => [],
        };
    }

    private function titleForScope(string $scopeType): string
    {
        return match ($scopeType) {
            'electrical' => 'Электроснабжение',
            'plumbing' => 'Водоснабжение и канализация',
            'heating' => 'Отопление',
            'ventilation' => 'Вентиляция',
            'openings' => 'Окна и двери',
            'finishing' => 'Отделка помещений',
            default => 'Строительные работы',
        };
    }

    private function normativeSearchKey(
        string $packageKey,
        string $scopeType,
        string $category,
        string $searchText,
        string $unit,
        string $quantityKey,
        ?string $normativeRateCode
    ): string {
        return implode('|', array_map(
            fn (string $value): string => $this->normalizeSearchPart($value),
            [
                $normativeRateCode ?? '',
                $packageKey,
                $scopeType,
                $category,
                $searchText,
                $unit,
                $quantityKey,
            ]
        ));
    }

    private function normalizeSearchPart(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }
}
