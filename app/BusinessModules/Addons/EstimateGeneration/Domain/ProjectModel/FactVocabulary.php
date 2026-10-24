<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\CanonicalSourceDecimal;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class FactVocabulary
{
    public const VERSION = 'project-facts:v1';

    public static function isWorkScopeFactType(string $type): bool
    {
        return in_array($type, ['technology_candidate', 'roof_geometry'], true);
    }

    public static function semanticProposal(?array $proposal, string $type, string $entityKey, array $value, ?string $unit): ?array
    {
        if ($proposal === null) return null;
        $kind = $proposal['kind'] ?? null;
        $keys = $kind === 'equipment_identity' ? ['schemaVersion', 'kind', 'identity']
            : ['schemaVersion', 'kind', 'parameter', 'dimension', 'comparator', 'threshold', 'unit', 'appliesTo', 'documentEdition', 'clause', 'conditions'];
        if (! self::exactKeys($proposal, $keys) || ($proposal['schemaVersion'] ?? null) !== 1
            || ! in_array($kind, ['equipment_identity', 'source_requirement'], true)
            || ($value['type'] ?? null) !== 'string' || ! is_string($value['data'] ?? null) || $unit !== null
            || ($kind === 'equipment_identity' ? $type !== 'equipment_specification'
                : self::documentObservation($type, $entityKey, $value, $unit) === null && ! self::isWorkScopeFactType($type))
            || strlen(json_encode($proposal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) > 8192) {
            throw new InvalidArgumentException('document_semantic_proposal_invalid');
        }
        if ($kind === 'equipment_identity') {
            return ['schemaVersion' => 1, 'kind' => $kind, 'identity' => self::semanticIdentity($proposal['identity'])];
        }
        $target = $proposal['appliesTo'];
        $conditions = $proposal['conditions'];
        if (! is_array($target) || ! self::exactKeys($target, ['subject', 'equipmentIdentity'])
            || ! in_array($proposal['dimension'], [null, 'length'], true)
            || ($conditions !== null && (! is_array($conditions) || ! array_is_list($conditions) || count($conditions) > 8))) {
            throw new InvalidArgumentException('document_semantic_proposal_invalid');
        }

        return ['schemaVersion' => 1, 'kind' => $kind,
            'parameter' => self::semanticSourceValue($proposal['parameter'], 160), 'dimension' => $proposal['dimension'],
            'comparator' => self::semanticSourceValue($proposal['comparator'], 3, 'comparator'),
            'threshold' => self::semanticSourceValue($proposal['threshold'], 64, 'decimal'),
            'unit' => self::semanticSourceValue($proposal['unit'], 40, 'length'),
            'appliesTo' => ['subject' => self::semanticSourceValue($target['subject'], 160),
                'equipmentIdentity' => $target['equipmentIdentity'] === null ? null : self::semanticIdentity($target['equipmentIdentity'])],
            'documentEdition' => self::semanticSourceValue($proposal['documentEdition'], 160),
            'clause' => self::semanticSourceValue($proposal['clause'], 500),
            'conditions' => $conditions === null ? null : array_map(static fn ($condition): array => self::semanticSourceValue($condition, 300), $conditions)];
    }

    public static function semanticSourceFields(array $proposal): array
    {
        $fields = [];
        $walk = function (array $node, string $path) use (&$walk, &$fields): void {
            if (self::exactKeys($node, ['data', 'evidenceRef', 'sourcePolygonOrNativeRef'])) {
                $fields[$path] = $node;
                return;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) $walk($value, $path === '' ? (string) $key : $path.'.'.$key);
            }
        };
        $walk($proposal, '');
        ksort($fields, SORT_STRING);

        return $fields;
    }

    public static function mapSemanticSources(?array $proposal, callable $map): ?array
    {
        if ($proposal === null) return null;
        if (self::exactKeys($proposal, ['data', 'evidenceRef', 'sourcePolygonOrNativeRef'])) return $map($proposal);
        foreach ($proposal as $key => $value) {
            if (is_array($value)) $proposal[$key] = self::mapSemanticSources($value, $map);
        }

        return $proposal;
    }

    private static function semanticIdentity(mixed $identity): array
    {
        if (! is_array($identity) || ! self::exactKeys($identity, ['manufacturer', 'model', 'variant', 'configuration'])) {
            throw new InvalidArgumentException('document_semantic_identity_invalid');
        }
        $result = [];
        foreach (['manufacturer', 'model', 'variant', 'configuration'] as $key) $result[$key] = self::semanticSourceValue($identity[$key], 160);

        return $result;
    }

    private static function semanticSourceValue(mixed $field, int $limit, string $domain = 'text'): array
    {
        if (! is_array($field) || ! self::exactKeys($field, ['data', 'evidenceRef', 'sourcePolygonOrNativeRef'])) {
            throw new InvalidArgumentException('document_semantic_field_invalid');
        }
        $data = $field['data'];
        $reference = $field['evidenceRef'];
        $source = $field['sourcePolygonOrNativeRef'];
        if (($data !== null && (! is_string($data) || trim($data) === '' || mb_strlen($data) > $limit
                || preg_match('/[\x00-\x1F\x7F]/u', $data) !== 0))
            || ($data === null && ($reference !== null || $source !== null))
            || (($reference === null) !== ($source === null))
            || ($reference !== null && (! is_string($reference) || preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/D', $reference) !== 1))
            || ($source !== null && ! FactSourceLocator::valid(['kind' => is_array($source) ? 'polygon' : 'native',
                'coordinate_space' => is_array($source) ? 'normalized_source_v1' : 'native', 'value' => $source]))
            || ($data !== null && match ($domain) {
                'decimal' => ! CanonicalSourceDecimal::isValid($data),
                'length' => self::factor('length', $data) === null,
                'comparator' => $data !== 'gte',
                default => false,
            })) {
            throw new InvalidArgumentException('document_semantic_field_invalid');
        }

        return ['data' => $data, 'evidenceRef' => $reference, 'sourcePolygonOrNativeRef' => $source];
    }

    public static function engineeringParameterAttributes(int $organizationId, int $projectId, int $sessionId,
        array $binding, string $name, string $role, array $definitionSource): array
    {
        if (! self::exactKeys($binding, ['entity_id', 'entity_source_version', 'work_key', 'work_binding_hash'])
            || ! self::exactKeys($definitionSource, ['evidence_id', 'source_version', 'evidence_fingerprint'])
            || ! is_string($binding['entity_id']) || ! is_string($binding['entity_source_version'])
            || ! is_string($binding['work_key']) || ! is_string($binding['work_binding_hash'])
            || ! is_string($definitionSource['evidence_id']) || ! is_string($definitionSource['source_version'])
            || ! is_string($definitionSource['evidence_fingerprint'])
            || ! in_array($role, ['observed_value', 'minimum_length_requirement'], true)
            || trim($name) !== $name || $name === '' || mb_strlen($name) > 160
            || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0
            || preg_match('/\A[a-f0-9]{64}\z/', $binding['work_binding_hash']) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/', $definitionSource['evidence_fingerprint']) !== 1) {
            throw new InvalidArgumentException('engineering_parameter_definition_invalid');
        }
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $binding['entity_source_version']);
        ProjectModelInvariant::scope($organizationId, $projectId, $sessionId, $definitionSource['source_version']);
        ProjectModelInvariant::id($binding['entity_id'], 'Engineering parameter entity');
        ProjectModelInvariant::id($binding['work_key'], 'Engineering parameter work');
        ProjectModelInvariant::id($definitionSource['evidence_id'], 'Engineering parameter evidence');
        if (! str_starts_with($definitionSource['evidence_id'], 'evidence:')) {
            throw new InvalidArgumentException('engineering_parameter_definition_invalid');
        }
        $identity = ['schema_version' => 1, 'scope' => [$organizationId, $projectId, $sessionId],
            'name' => $name, 'dimension' => 'length', 'binding' => $binding, 'definition_source' => $definitionSource];
        $parameterId = hash('sha256', DerivedQuantityIdentity::canonicalJson($identity));

        return ['engineering_parameter' => ['schema_version' => 1, 'parameter_id' => $parameterId,
            'definition_id' => hash('sha256', DerivedQuantityIdentity::canonicalJson([...$identity, 'role' => $role])),
            'name' => $name, 'dimension' => 'length', 'role' => $role, 'binding' => $binding, 'definition_source' => $definitionSource]];
    }

    public static function engineeringParameterType(array $attributes): string
    {
        $definition = $attributes['engineering_parameter'] ?? null;
        $definitionId = is_array($definition) ? ($definition['definition_id'] ?? null) : null;
        if (! is_string($definitionId) || preg_match('/\A[a-f0-9]{64}\z/', $definitionId) !== 1) {
            throw new InvalidArgumentException('engineering_parameter_definition_invalid');
        }

        return 'engineering_parameter_'.substr($definitionId, 0, 40);
    }

    public static function assertEngineeringParameter(Fact $fact): void
    {
        if ($fact->semanticAttributes === [] && ! str_starts_with($fact->type, 'engineering_parameter_')) {
            return;
        }
        $attributes = $fact->semanticAttributes;
        $definition = $attributes['engineering_parameter'] ?? null;
        if (! self::exactKeys($attributes, ['engineering_parameter']) || ! is_array($definition)
            || ! self::exactKeys($definition, ['schema_version', 'parameter_id', 'definition_id', 'name', 'dimension', 'role', 'binding', 'definition_source'])
            || $definition['schema_version'] !== 1 || $definition['dimension'] !== 'length'
            || ! is_array($definition['binding']) || ! is_array($definition['definition_source'])
            || ! is_string($definition['name']) || ! is_string($definition['role'])) {
            throw new InvalidArgumentException('engineering_parameter_definition_invalid');
        }
        $expected = self::engineeringParameterAttributes($fact->organizationId, $fact->projectId, $fact->sessionId,
            $definition['binding'], $definition['name'], $definition['role'], $definition['definition_source']);
        if (DerivedQuantityIdentity::canonicalJson($expected) !== DerivedQuantityIdentity::canonicalJson($attributes)
            || self::engineeringParameterType($expected) !== $fact->type
            || ! in_array($definition['definition_source']['evidence_id'], $fact->evidenceIds, true)
            || ($definition['role'] === 'observed_value'
                && [$fact->entityId, $fact->sourceVersion] !== [$definition['binding']['entity_id'], $definition['binding']['entity_source_version']])
            || ($definition['role'] === 'minimum_length_requirement' && $fact->sourceVersion !== $definition['definition_source']['source_version'])
            || ($fact->unit !== null && self::factor('length', $fact->unit) === null)
            || ($fact->value !== null && (! CanonicalSourceDecimal::isPositive($fact->value) || $fact->unit === null))
            || ($fact->value === null && ! in_array($fact->status, ['candidate', 'unresolved', 'invalidated'], true))) {
            throw new InvalidArgumentException('engineering_parameter_fact_invalid');
        }
    }

    public static function meetsMinimumLength(mixed $value, ?string $unit, mixed $minimum, ?string $minimumUnit): bool
    {
        $factor = self::factor('length', $unit);
        $minimumFactor = self::factor('length', $minimumUnit);
        if (! CanonicalSourceDecimal::isPositive($value) || ! CanonicalSourceDecimal::isPositive($minimum)
            || $factor === null || $minimumFactor === null) {
            throw new InvalidArgumentException('engineering_length_operand_invalid');
        }

        return BigDecimal::of($value)->multipliedBy($factor)
            ->isGreaterThanOrEqualTo(BigDecimal::of($minimum)->multipliedBy($minimumFactor));
    }

    private static function exactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff(array_keys($value), $keys) === [];
    }

    /** Direct measurements only; a circuit length never supplies tray or grounding lengths. */
    private const ENGINEERING_MEASUREMENTS = [
        'equipment_count' => ['entities' => ['equipment'], 'parameter' => 'equipment_count', 'group' => 'count', 'unit' => 'count', 'purpose' => 'equipment.count'],
        'radiator_count' => ['entities' => ['radiator'], 'parameter' => 'equipment_count', 'group' => 'count', 'unit' => 'count', 'purpose' => 'heating.radiators'],
        'water_pipe_length' => ['entities' => ['water_pipe'], 'parameter' => 'pipe_length', 'group' => 'length', 'unit' => 'm', 'purpose' => 'plumbing.pipe'],
        'sewer_pipe_length' => ['entities' => ['sewer_pipe'], 'parameter' => 'pipe_length', 'group' => 'length', 'unit' => 'm', 'purpose' => 'sewerage.pipe'],
        'process_pipe_length' => ['entities' => ['technological_pipeline'], 'parameter' => 'pipe_length', 'group' => 'length', 'unit' => 'm', 'purpose' => 'pipelines.install'],
        'cable_length' => ['entities' => ['electrical_circuit'], 'parameter' => 'cable_length', 'group' => 'length', 'unit' => 'm', 'purpose' => 'electrical.main_cable'],
        'duct_length' => ['entities' => ['ventilation_duct'], 'parameter' => 'duct_length', 'group' => 'length', 'unit' => 'm', 'purpose' => 'ventilation.duct'],
    ];

    public static function foundationFormulas(): array
    {
        return [
            'foundation_beam_volume' => ['entity' => 'foundation_beam', 'parameters' => ['length', 'width', 'height']],
            'foundation_slab_volume' => ['entity' => 'foundation_slab', 'parameters' => ['area', 'thickness']],
        ];
    }

    public static function entitySections(string $type): array
    {
        return match ($type) {
            'roof', 'roof_facet', 'roof_opening' => ['roofing'],
            'room', 'wall', 'opening' => ['finishing', 'structures'],
            'site' => ['earthworks'],
            'foundation_beam', 'foundation_slab' => ['structures'],
            'equipment' => ['equipment'], 'radiator' => ['heating'], 'water_pipe' => ['water_supply'], 'sewer_pipe' => ['sewerage'],
            'technological_pipeline' => ['technological_pipelines'], 'electrical_circuit' => ['electricity'], 'ventilation_duct' => ['ventilation'],
            default => [],
        };
    }

    public static function engineeringFormulas(): array
    {
        return self::ENGINEERING_MEASUREMENTS;
    }

    public static function engineeringTypes(): array
    {
        return array_values(array_unique(array_merge(...array_column(self::ENGINEERING_MEASUREMENTS, 'entities'))));
    }

    public static function engineeringParameters(): array
    {
        return array_values(array_unique(array_column(self::ENGINEERING_MEASUREMENTS, 'parameter')));
    }

    public static function roomDimensionObservation(string $entityKey, string $parameter, mixed $value, ?string $unit): ?array
    {
        if (preg_match('/^room[:._-][a-z0-9][a-z0-9._:-]{0,119}$/iD', $entityKey) !== 1
            || ! in_array($parameter, ['length', 'width', 'height'], true)
            || (! is_string($value) && ! is_int($value)) || ! CanonicalSourceDecimal::isPositive((string) $value)
            || self::factor('length', $unit) === null) {
            return null;
        }

        return ['parameter' => $parameter, 'unit' => self::unit($unit)];
    }

    /** Validates the stated measurement, without deriving quantities from a photograph or context. */
    public static function engineeringObservation(string $entityKey, string $parameter, mixed $value, ?string $unit): ?array
    {
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A[0-9]{1,15}(?:\.[0-9]{1,8})?\z/', (string) $value) !== 1) {
            return null;
        }
        $parameter = self::parameter($parameter);
        foreach (self::ENGINEERING_MEASUREMENTS as $formula => $definition) {
            foreach ($definition['entities'] as $kind) {
                if (preg_match('/^'.preg_quote($kind, '/').'[:._-][a-z0-9][a-z0-9._:-]{0,119}$/iD', $entityKey) !== 1
                    || $parameter !== $definition['parameter'] || self::factor($definition['group'], $unit) === null) {
                    continue;
                }
                $number = BigDecimal::of((string) $value);
                if (! $number->isGreaterThan(0) || ($definition['group'] === 'count' && $number->strippedOfTrailingZeros()->getScale() > 0)) {
                    return null;
                }

                return [...$definition, 'formula' => $formula, 'semantic_type' => $kind];
            }
        }

        return null;
    }

    private const DOCUMENT_DOMAINS = [
        'heating_system' => 'heating', 'heating_specification' => 'heating', 'radiator_specification' => 'heating', 'heating_test_program' => 'heating',
        'water_supply_system' => 'water_supply', 'water_supply_specification' => 'water_supply', 'water_supply_test_program' => 'water_supply',
        'sewerage_system' => 'sewerage', 'sewerage_specification' => 'sewerage', 'sewerage_test_program' => 'sewerage',
        'ventilation_system' => 'ventilation', 'ventilation_specification' => 'ventilation', 'ventilation_test_program' => 'ventilation',
        'electricity_system' => 'electricity', 'electrical_specification' => 'electricity', 'electricity_test_program' => 'electricity',
        'automation_system' => 'automation', 'automation_specification' => 'automation', 'automation_test_program' => 'automation',
        'pipeline_specification' => 'technological_pipelines', 'technological_pipeline_specification' => 'technological_pipelines', 'pipeline_test_program' => 'technological_pipelines',
        'equipment_specification' => 'equipment',
        'industrial_specification' => 'industrial', 'technology_requirements' => 'industrial', 'industry_work_checklist' => 'industrial',
    ];

    private const DOCUMENT_ENTITIES = [
        'heating' => ['heating_system', 'radiator', 'heater', 'equipment'],
        'water_supply' => ['water_supply_system', 'water_pipe', 'equipment'],
        'sewerage' => ['sewerage_system', 'sewer_pipe', 'equipment'],
        'ventilation' => ['ventilation_system', 'ventilation_duct', 'equipment'],
        'electricity' => ['electrical_system', 'electrical_circuit', 'equipment'],
        'automation' => ['automation_system', 'control_system', 'equipment'],
        'technological_pipelines' => ['technological_pipeline', 'process_system', 'equipment'],
        'equipment' => ['equipment'],
    ];

    public static function documentTypes(): array
    {
        return array_keys(self::DOCUMENT_DOMAINS);
    }

    public static function documentDomain(string $type): ?string
    {
        return self::DOCUMENT_DOMAINS[$type] ?? null;
    }

    public static function documentSupportsEntity(string $type, string $entityType): bool
    {
        $domain = self::documentDomain($type);
        $kinds = array_values(array_unique(array_merge(array_keys(self::PARAMETERS), ...array_values(self::DOCUMENT_ENTITIES))));

        return $domain === 'industrial' ? in_array($entityType, $kinds, true)
            : in_array($entityType, self::DOCUMENT_ENTITIES[$domain ?? ''] ?? [], true);
    }

    /** A cited text is a document context, never a numeric measurement or a generated design. */
    public static function documentObservation(string $type, string $entityKey, array $value, ?string $unit): ?array
    {
        if (self::documentDomain($type) === null || $unit !== null || ($value['type'] ?? null) !== 'string'
            || ! is_string($value['data'] ?? null) || trim($value['data']) === '' || mb_strlen($value['data']) > 500
            || preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', $value['data']) === 1) {
            return null;
        }
        $kinds = array_values(array_unique(array_merge(array_keys(self::PARAMETERS), ...array_values(self::DOCUMENT_ENTITIES))));
        usort($kinds, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($kinds as $kind) {
            if (preg_match('/^'.preg_quote($kind, '/').'[:._-][a-z0-9][a-z0-9._:-]{0,119}$/iD', $entityKey) === 1 && self::documentSupportsEntity($type, $kind)) {
                return ['semantic_type' => $kind, 'excerpt' => trim($value['data'])];
            }
        }

        return null;
    }

    private const PARAMETERS = [
        'room' => ['area', 'length', 'width', 'height'],
        'wall' => ['length', 'height', 'thickness'],
        'opening' => ['width', 'height', 'area'],
        'site' => ['area', 'depth'],
        'foundation_beam' => ['length', 'width', 'height'],
        'foundation_slab' => ['area', 'thickness'],
        'roof' => ['plan_area', 'slope_rise', 'slope_run'],
        'roof_facet' => ['plan_area', 'slope_rise', 'slope_run'],
        'roof_opening' => ['area'],
    ];

    private const ALIASES = [
        'room_name' => ['room_name', 'название помещения', 'наименование помещения'],
        'room_number' => ['room_number', 'номер помещения', 'номер комнаты'],
        'room_floor' => ['room_floor', 'этаж помещения'],
        'room_zone' => ['room_zone', 'зона помещения'],
        'area' => ['area', 'room_area', 'floor_area', 'opening_area', 'площадь', 'площадь пола', 'площадь помещения', 'площадь проёма', 'площадь проема'],
        'length' => ['length', 'room_length', 'wall_length', 'длина', 'длина стены'],
        'width' => ['width', 'room_width', 'opening_width', 'ширина', 'ширина проёма', 'ширина проема'],
        'height' => ['height', 'room_height', 'wall_height', 'opening_height', 'высота', 'высота стены', 'высота помещения', 'высота проёма', 'высота проема'],
        'thickness' => ['thickness', 'wall_thickness', 'толщина', 'толщина стены'],
        'depth' => ['depth', 'excavation_depth', 'глубина', 'глубина выемки'],
        'plan_area' => ['plan_area', 'roof_plan_area', 'площадь кровли в плане'],
        'slope_rise' => ['slope_rise', 'roof_slope_rise', 'подъём ската', 'подъем ската'],
        'slope_run' => ['slope_run', 'roof_slope_run', 'заложение ската'],
        'equipment_count' => ['equipment_count', 'количество оборудования', 'число единиц оборудования'],
        'pipe_length' => ['pipe_length', 'длина трубопровода', 'длина трубы'],
        'cable_length' => ['cable_length', 'длина кабеля'],
        'duct_length' => ['duct_length', 'длина воздуховода'],
        'equipment_specification' => ['equipment_specification', 'спецификация оборудования'],
        'heating_specification' => ['heating_specification', 'спецификация отопления'],
        'water_supply_specification' => ['water_supply_specification', 'спецификация водоснабжения'],
        'electrical_specification' => ['electrical_specification', 'спецификация электрики'],
        'pipeline_specification' => ['pipeline_specification', 'спецификация трубопроводов'],
        'heating_test_program' => ['heating_test_program', 'программа испытаний отопления'],
        'water_supply_test_program' => ['water_supply_test_program', 'программа испытаний водоснабжения'],
        'electricity_test_program' => ['electricity_test_program', 'программа испытаний электрики'],
        'industrial_specification' => ['industrial_specification', 'промышленная спецификация'],
        'technology_requirements' => ['technology_requirements', 'технологические требования'],
        'industry_work_checklist' => ['industry_work_checklist', 'отраслевой перечень работ'],
    ];

    private const METRIC_UNITS = [
        'mm' => ['m', '0.001'], 'cm' => ['m', '0.01'], 'm' => ['m', '1'], 'in' => ['m', '0.0254'], 'ft' => ['m', '0.3048'],
        'mm2' => ['m2', '0.000001'], 'cm2' => ['m2', '0.0001'], 'm2' => ['m2', '1'],
        'mm3' => ['m3', '0.000000001'], 'cm3' => ['m3', '0.000001'], 'm3' => ['m3', '1'],
        'count' => ['count', '1'], 'kg' => ['kg', '1'], 't' => ['kg', '1000'], 'h' => ['h', '1'],
    ];

    public static function entityType(string $entityKey): ?string
    {
        foreach (array_keys(self::PARAMETERS) as $type) {
            if (preg_match('/^'.preg_quote($type, '/').'[:._-]/D', mb_strtolower(trim($entityKey))) === 1) {
                if ($type === 'roof' && preg_match('/^roof_(facet|opening)[:._-]/D', mb_strtolower(trim($entityKey))) === 1) {
                    continue;
                }

                return $type;
            }
        }
        foreach (self::engineeringTypes() as $type) {
            if (preg_match('/^'.preg_quote($type, '/').'[:._-][a-z0-9][a-z0-9._:-]{0,119}$/iD', trim($entityKey)) === 1) {
                return $type;
            }
        }

        return null;
    }

    public static function parameter(string $type): string
    {
        $type = mb_strtolower(trim(preg_replace('/([a-z])([A-Z])/', '$1_$2', $type) ?? $type));
        if (preg_match('/\Awork_quantity_[a-f0-9]{48}\z/', $type) === 1) {
            return 'quantity';
        }
        foreach (self::ALIASES as $canonical => $aliases) {
            if (in_array($type, $aliases, true)) {
                return $canonical;
            }
        }

        return $type;
    }

    public static function aliases(string $parameter): array
    {
        return self::ALIASES[self::parameter($parameter)] ?? [$parameter];
    }

    public static function supports(string $entityType, string $parameter): bool
    {
        foreach (self::ENGINEERING_MEASUREMENTS as $definition) {
            if (in_array($entityType, $definition['entities'], true) && self::parameter($parameter) === $definition['parameter']) {
                return true;
            }
        }

        return in_array(self::parameter($parameter), self::PARAMETERS[$entityType] ?? [], true);
    }

    public static function unit(?string $unit): ?string
    {
        if ($unit === null) {
            return null;
        }
        $normalized = str_replace(['²', '³', '^', ' '], ['2', '3', '', ''], mb_strtolower(trim($unit)));

        return match ($normalized) {
            'мм', 'mm' => 'mm', 'см', 'cm' => 'cm', 'м', 'm' => 'm',
            'мм2', 'mm2' => 'mm2', 'см2', 'cm2' => 'cm2', 'м2', 'm2' => 'm2',
            'мм3', 'mm3' => 'mm3', 'см3', 'cm3' => 'cm3', 'м3', 'm3' => 'm3',
            'шт', 'шт.', 'pcs', 'count' => 'count',
            'кг', 'kg' => 'kg', 'т', 't' => 't', 'ч', 'час', 'h' => 'h',
            'in', 'inch', 'inches', 'дюйм', 'дюймы' => 'in',
            'ft', 'foot', 'feet', 'фут', 'футы' => 'ft',
            default => $normalized,
        };
    }

    public static function numericUnit(?string $unit): bool
    {
        return isset(self::METRIC_UNITS[self::unit($unit) ?? '']);
    }

    public static function factor(string $group, ?string $unit): ?string
    {
        [$base, $factor] = self::METRIC_UNITS[self::unit($unit) ?? ''] ?? [null, null];

        return $base === match ($group) {
            'length' => 'm', 'area' => 'm2', 'volume' => 'm3', 'count' => 'count',
            'mass' => 'kg', 'time' => 'h', default => null,
        } ? $factor : null;
    }

    public static function measurementSignature(string $value, ?string $unit): string
    {
        $unit = self::unit($unit);
        [$canonicalUnit, $factor] = self::METRIC_UNITS[$unit ?? ''] ?? [$unit ?? '', '1'];

        $decimal = (string) BigDecimal::of($value)->multipliedBy($factor);

        return $canonicalUnit.':'.(str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal);
    }
}
