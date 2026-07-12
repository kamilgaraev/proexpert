<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

final readonly class VectorGeometryData
{
    private const MAX_ITEMS = 100_000;

    private const MAX_COORDINATE_COMPONENTS = 500_000;

    private const MAX_PAGES = 200;

    private const MAX_SEGMENTS = 100_000;

    private const MAX_TEXT_CHARACTERS = 1_000_000;

    private const MAX_NESTED_REFERENCES = 100_000;

    private const MAX_REFERENCE_LENGTH = 512;

    private const MAX_TEXT_LENGTH = 4096;

    private const MAX_DEPTH = 10;

    /**
     * @param  array<int, float|int>  $bounds
     * @param  array<int, array<string, mixed>>  $layers
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  array<int, array<string, mixed>>  $entities
     * @param  array<int, array<string, mixed>>  $texts
     * @param  array<int, array<string, mixed>>  $dimensions
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array<string, mixed>>  $scaleCandidates
     * @param  array<int, array<string, mixed>>  $warnings
     */
    public function __construct(
        public int $schemaVersion,
        public string $runtimeVersion,
        public string $sourceFingerprint,
        public ?string $sourceUnit,
        public string $unitStatus,
        public array $bounds,
        public array $layers,
        public array $blocks,
        public array $entities,
        public array $texts,
        public array $dimensions,
        public array $pages,
        public array $scaleCandidates,
        public array $warnings,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = ['schema_version', 'runtime_version', 'source_fingerprint', 'source_unit', 'unit_status',
            'bounds', 'layers', 'blocks', 'entities', 'texts', 'dimensions', 'pages', 'scale_candidates', 'warnings'];
        if (array_diff(array_keys($data), $allowed) !== [] || array_diff($allowed, array_keys($data)) !== []) {
            throw new \InvalidArgumentException('cad_contract_fields_invalid');
        }
        if ($data['schema_version'] !== 1
            || ! preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $data['source_fingerprint'])) {
            throw new \InvalidArgumentException('cad_contract_provenance_invalid');
        }
        if (! is_string($data['runtime_version']) || preg_match('/^(?:cad-geometry:v1;(?:ezdxf:1\.4\.4|libredwg:0\.13\.4)|pdf-geometry:v1;pypdfium2:5\.8\.0)$/D', $data['runtime_version']) !== 1) {
            throw new \InvalidArgumentException('geometry_contract_runtime_invalid');
        }
        if (! in_array($data['unit_status'], ['confirmed', 'unknown', 'ambiguous', 'conflicting'], true)) {
            throw new \InvalidArgumentException('cad_contract_unit_invalid');
        }
        if (($data['unit_status'] === 'confirmed') !== is_string($data['source_unit'])
            || (is_string($data['source_unit']) && ! in_array($data['source_unit'], ['mm', 'cm', 'm', 'in', 'ft'], true))) {
            throw new \InvalidArgumentException('geometry_contract_unit_invalid');
        }
        foreach (['bounds', 'layers', 'blocks', 'entities', 'texts', 'dimensions', 'pages', 'scale_candidates', 'warnings'] as $field) {
            if (! is_array($data[$field])) {
                throw new \InvalidArgumentException('cad_contract_field_invalid');
            }
        }
        self::assertStructuralBudgets($data);
        self::assertDepth($data);
        self::assertBounds($data['bounds']);
        self::assertCollection($data['layers'], ['name', 'visible'], ['name', 'visible'], 'layer');
        self::assertCollection($data['blocks'], ['name', 'handle', 'owner', 'entities'], ['name', 'handle', 'owner', 'entities'], 'block');
        self::assertCollection($data['entities'], ['handle', 'type', 'layer'], ['handle', 'type', 'layer', 'points', 'segments', 'center', 'radius', 'start_angle', 'end_angle', 'closed', 'block', 'transform', 'transform_lineage', 'source_lineage', 'source_member_handle', 'layout', 'owner', 'bbox', 'style', 'semantic'], 'entity');
        self::assertCollection($data['texts'], ['handle', 'type', 'layer', 'text', 'position', 'layout'], ['handle', 'type', 'layer', 'text', 'position', 'layout', 'source_operator', 'source_lineage', 'source_member_handle', 'block', 'transform', 'owner', 'bbox'], 'text');
        self::assertCollection($data['dimensions'], ['handle', 'type', 'layer', 'text', 'layout'], ['handle', 'type', 'layer', 'text', 'layout', 'definition_points', 'source_lineage', 'source_member_handle', 'block', 'transform', 'owner'], 'dimension');
        self::assertCollection($data['pages'], ['page_number', 'width', 'height', 'rotation', 'media_box', 'crop_box', 'transform', 'classification'], ['page_number', 'width', 'height', 'rotation', 'media_box', 'crop_box', 'transform', 'classification'], 'page');
        self::assertCollection($data['scale_candidates'], ['value', 'source'], ['value', 'source', 'confidence'], 'scale_candidate');
        self::assertCollection($data['warnings'], ['code'], ['code', 'count', 'safe_context'], 'warning');
        self::assertLayers($data['layers']);
        self::assertBlocks($data['blocks']);
        self::assertPages($data['pages']);
        self::assertScaleCandidates($data['scale_candidates']);
        self::assertWarnings($data['warnings']);
        foreach ($data['warnings'] as $warning) {
            if (in_array($warning['code'], ['unsupported_entities', 'unknown_entities', 'skipped_entities', 'partial_geometry'], true)) {
                throw new \InvalidArgumentException('geometry_contract_blocking_warning');
            }
        }
        $handles = [];
        $pointCount = 0;
        foreach (['entities', 'texts', 'dimensions'] as $collection) {
            foreach ($data[$collection] as $item) {
                $handle = $item['handle'];
                if (! self::isReferenceString($handle) || isset($handles[$handle])) {
                    throw new \InvalidArgumentException('geometry_contract_duplicate_handle');
                }
                $handles[$handle] = true;
                if (isset($item['text']) && (! is_string($item['text']) || mb_strlen($item['text']) > self::MAX_TEXT_LENGTH)) {
                    throw new \InvalidArgumentException('geometry_contract_text_invalid');
                }
                $pointCount += self::countCoordinateValues($item);
                if ($collection === 'entities') {
                    self::assertEntityDetails($item);
                } elseif ($collection === 'texts') {
                    self::assertTextDetails($item);
                } else {
                    self::assertDimensionDetails($item);
                }
            }
        }
        $pointCount += self::countTopLevelCoordinates($data);
        if ($pointCount > self::MAX_COORDINATE_COMPONENTS) {
            throw new \InvalidArgumentException('geometry_contract_points_limit');
        }

        return new self(
            $data['schema_version'], $data['runtime_version'], $data['source_fingerprint'], $data['source_unit'],
            $data['unit_status'], $data['bounds'], $data['layers'], $data['blocks'], $data['entities'],
            $data['texts'], $data['dimensions'], $data['pages'], $data['scale_candidates'], $data['warnings']
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'runtime_version' => $this->runtimeVersion,
            'source_fingerprint' => $this->sourceFingerprint,
            'source_unit' => $this->sourceUnit,
            'unit_status' => $this->unitStatus,
            'bounds' => $this->bounds,
            'layers' => $this->layers,
            'blocks' => $this->blocks,
            'entities' => $this->entities,
            'texts' => $this->texts,
            'dimensions' => $this->dimensions,
            'pages' => $this->pages,
            'scale_candidates' => $this->scaleCandidates,
            'warnings' => $this->warnings,
        ];
    }

    public function payloadSha256(): string
    {
        $payload = $this->toArray();
        self::sortRecursive($payload);

        return hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }

    /** @param array<int, mixed> $items @param array<int, string> $required @param array<int, string> $allowed */
    private static function assertCollection(array $items, array $required, array $allowed, string $type): void
    {
        if (count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException('geometry_contract_items_limit');
        }
        foreach ($items as $item) {
            if (! is_array($item) || array_diff($required, array_keys($item)) !== [] || array_diff(array_keys($item), $allowed) !== []) {
                throw new \InvalidArgumentException('geometry_contract_'.$type.'_invalid');
            }
            self::assertFinite($item);
        }
    }

    /** @param array<int, mixed> $bounds */
    private static function assertBounds(array $bounds): void
    {
        if ($bounds === []) {
            return;
        }
        if (count($bounds) !== 4 || ! self::isFiniteNumber($bounds[0]) || ! self::isFiniteNumber($bounds[1]) || ! self::isFiniteNumber($bounds[2]) || ! self::isFiniteNumber($bounds[3])) {
            throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
        }
        self::assertFinite($bounds);
        if ((float) $bounds[0] > (float) $bounds[2] || (float) $bounds[1] > (float) $bounds[3]) {
            throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
        }
    }

    /** @param array<mixed> $value */
    private static function assertFinite(array $value): void
    {
        array_walk_recursive($value, static function (mixed $item): void {
            if (is_float($item) && ! is_finite($item)) {
                throw new \InvalidArgumentException('geometry_contract_number_invalid');
            }
        });
    }

    private static function assertDepth(mixed $value, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('geometry_contract_depth_invalid');
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                self::assertDepth($child, $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $item */
    private static function countCoordinateValues(array $item): int
    {
        $count = 0;
        foreach (['position', 'center', 'definition_points', 'transform', 'transform_lineage', 'bbox'] as $field) {
            if (isset($item[$field])) {
                $count += self::countNumericLeaves($item[$field]);
            }
        }
        if (is_array($item['segments'] ?? null)) {
            foreach ($item['segments'] as $segment) {
                $count += self::countNumericLeaves($segment['points'] ?? []);
            }
        } elseif (isset($item['points'])) {
            $count += self::countNumericLeaves($item['points']);
        }

        return $count;
    }

    private static function countNumericLeaves(mixed $value): int
    {
        if (is_int($value) || is_float($value)) {
            return 1;
        }
        if (! is_array($value)) {
            return 0;
        }

        $count = 0;
        foreach ($value as $child) {
            $count += self::countNumericLeaves($child);
        }

        return $count;
    }

    /** @param array<string, mixed> $entity */
    private static function assertEntityDetails(array $entity): void
    {
        foreach (['handle', 'type', 'layer'] as $field) {
            if (! self::isReferenceString($entity[$field])) {
                throw new \InvalidArgumentException('geometry_contract_entity_invalid');
            }
        }
        foreach (['layout', 'owner', 'block', 'source_operator', 'source_member_handle'] as $field) {
            if (array_key_exists($field, $entity) && ! self::isReferenceString($entity[$field])) {
                throw new \InvalidArgumentException('geometry_contract_entity_invalid');
            }
        }
        $type = $entity['type'];
        if (! in_array($type, ['line', 'arc', 'circle', 'lwpolyline', 'polyline', 'insert', 'path'], true)) {
            throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
        }
        if ($type === 'line' && (! is_array($entity['points'] ?? null) || count($entity['points']) !== 2)) {
            throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
        }
        if (in_array($type, ['lwpolyline', 'polyline'], true)
            && (! is_array($entity['points'] ?? null) || count($entity['points']) < 2 || ! is_bool($entity['closed'] ?? null))) {
            throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
        }
        if (in_array($type, ['arc', 'circle'], true)) {
            self::assertCoordinate($entity['center'] ?? null);
            if (! self::isFiniteNumber($entity['radius'] ?? null) || (float) $entity['radius'] <= 0) {
                throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
            }
            if ($type === 'arc' && (! self::isFiniteNumber($entity['start_angle'] ?? null) || ! self::isFiniteNumber($entity['end_angle'] ?? null))) {
                throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
            }
        }
        if ($type === 'insert') {
            if (! is_string($entity['block'] ?? null) || $entity['block'] === '' || ! self::isMatrix44($entity['transform'] ?? null)) {
                throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
            }
        }
        if ($type === 'path' && (! is_array($entity['segments'] ?? null) || $entity['segments'] === [])) {
            throw new \InvalidArgumentException('geometry_contract_entity_geometry_invalid');
        }
        if (isset($entity['points'])) {
            self::assertCoordinates($entity['points']);
        }
        if (isset($entity['transform']) && $type !== 'insert') {
            self::assertTransform($entity['transform']);
        }
        if (isset($entity['transform_lineage'])) {
            if (! is_array($entity['transform_lineage'])) {
                throw new \InvalidArgumentException('geometry_contract_transform_invalid');
            }
            foreach ($entity['transform_lineage'] as $transform) {
                self::assertTransform($transform);
            }
        }
        if (isset($entity['source_member_handle']) && (! is_string($entity['source_member_handle']) || $entity['source_member_handle'] === '')) {
            throw new \InvalidArgumentException('geometry_contract_entity_invalid');
        }
        if (isset($entity['source_lineage']) && ! self::isReferenceList($entity['source_lineage'])) {
            throw new \InvalidArgumentException('geometry_contract_entity_invalid');
        }
        if (isset($entity['segments'])) {
            if (! is_array($entity['segments'])) {
                throw new \InvalidArgumentException('geometry_contract_segment_invalid');
            }
            foreach ($entity['segments'] as $segment) {
                $required = ['operator', 'points', 'source_indices', 'closes_subpath'];
                if (! is_array($segment)
                    || array_diff($required, array_keys($segment)) !== []
                    || array_diff(array_keys($segment), $required) !== []
                    || ! in_array($segment['operator'], ['move', 'line', 'curve'], true)
                    || ! is_array($segment['points'])
                    || ! is_array($segment['source_indices'])
                    || ! is_bool($segment['closes_subpath'])) {
                    throw new \InvalidArgumentException('geometry_contract_segment_invalid');
                }
                self::assertCoordinates($segment['points']);
                $expectedPoints = match ($segment['operator']) {
                    'move' => 1,
                    'line' => 2,
                    'curve' => 4,
                };
                if (count($segment['points']) !== $expectedPoints) {
                    throw new \InvalidArgumentException('geometry_contract_segment_invalid');
                }
                $indices = $segment['source_indices'];
                $expectedIndices = $segment['operator'] === 'curve' ? 3 : 1;
                if (count($indices) !== $expectedIndices || array_filter($indices, static fn (mixed $value): bool => is_int($value) && $value >= 0) !== $indices
                    || array_values(array_unique($indices)) !== $indices || $indices !== array_values(array_unique($indices, SORT_NUMERIC))) {
                    throw new \InvalidArgumentException('geometry_contract_segment_invalid');
                }
                $sorted = $indices;
                sort($sorted, SORT_NUMERIC);
                if ($sorted !== $indices) {
                    throw new \InvalidArgumentException('geometry_contract_segment_invalid');
                }
            }
        }
        if (isset($entity['style'])) {
            $required = ['fill_mode', 'stroke', 'fill_rgba', 'stroke_rgba', 'stroke_width', 'line_cap', 'line_join'];
            if (! is_array($entity['style']) || array_diff($required, array_keys($entity['style'])) !== [] || array_diff(array_keys($entity['style']), $required) !== []) {
                throw new \InvalidArgumentException('geometry_contract_style_invalid');
            }
            if (! is_int($entity['style']['fill_mode'] ?? null) || ! is_bool($entity['style']['stroke'] ?? null)
                || ! self::isFiniteNumber($entity['style']['stroke_width'] ?? null) || (float) $entity['style']['stroke_width'] < 0
                || ! is_int($entity['style']['line_cap'] ?? null) || ! is_int($entity['style']['line_join'] ?? null)) {
                throw new \InvalidArgumentException('geometry_contract_style_invalid');
            }
            foreach (['fill_rgba', 'stroke_rgba'] as $color) {
                if ($entity['style'][$color] !== null && ! self::isRgba($entity['style'][$color])) {
                    throw new \InvalidArgumentException('geometry_contract_style_invalid');
                }
            }
        }
        if (isset($entity['bbox'])) {
            if (! is_array($entity['bbox']) || array_diff(array_keys($entity['bbox']), ['x', 'y', 'width', 'height']) !== []) {
                throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
            }
            foreach (['x', 'y', 'width', 'height'] as $field) {
                if (! self::isFiniteNumber($entity['bbox'][$field] ?? null)) {
                    throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
                }
            }
            if ((float) $entity['bbox']['width'] < 0 || (float) $entity['bbox']['height'] < 0) {
                throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
            }
        }
        if (isset($entity['semantic'])) {
            $semantic = $entity['semantic'];
            $keys = is_array($semantic) ? array_keys($semantic) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['height', 'kind', 'offset', 'opening_type', 'wall_handle', 'width']
                || $semantic['kind'] !== 'opening' || ! self::isReferenceString($semantic['wall_handle'])
                || ! in_array($semantic['opening_type'], ['door', 'window', 'gate', 'other'], true)
                || ! self::isFiniteNumber($semantic['offset']) || (float) $semantic['offset'] < 0
                || ! self::isFiniteNumber($semantic['width']) || (float) $semantic['width'] <= 0
                || ! self::isFiniteNumber($semantic['height']) || (float) $semantic['height'] <= 0) {
                throw new \InvalidArgumentException('geometry_contract_entity_semantic_invalid');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $layers */
    private static function assertLayers(array $layers): void
    {
        foreach ($layers as $layer) {
            if (! self::isReferenceString($layer['name']) || ! is_bool($layer['visible'])) {
                throw new \InvalidArgumentException('geometry_contract_layer_invalid');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private static function assertBlocks(array $blocks): void
    {
        foreach ($blocks as $block) {
            if (! self::isReferenceString($block['name']) || ! self::isReferenceString($block['handle'])
                || ! self::isReferenceString($block['owner']) || ! self::isReferenceList($block['entities'])) {
                throw new \InvalidArgumentException('geometry_contract_block_invalid');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $pages */
    private static function assertPages(array $pages): void
    {
        foreach ($pages as $page) {
            if (! is_int($page['page_number']) || $page['page_number'] < 1
                || ! self::isFiniteNumber($page['width']) || (float) $page['width'] <= 0
                || ! self::isFiniteNumber($page['height']) || (float) $page['height'] <= 0
                || ! is_int($page['rotation']) || ! in_array($page['rotation'], [0, 90, 180, 270], true)
                || ! in_array($page['classification'], ['vector', 'raster', 'mixed', 'empty'], true)) {
                throw new \InvalidArgumentException('geometry_contract_page_invalid');
            }
            self::assertBox($page['media_box']);
            self::assertBox($page['crop_box']);
            self::assertTransform($page['transform']);
        }
    }

    /** @param array<int, array<string, mixed>> $candidates */
    private static function assertScaleCandidates(array $candidates): void
    {
        foreach ($candidates as $candidate) {
            if (! self::isFiniteNumber($candidate['value']) || (float) $candidate['value'] <= 0 || ! self::isReferenceString($candidate['source'])
                || (isset($candidate['confidence']) && (! self::isFiniteNumber($candidate['confidence']) || (float) $candidate['confidence'] < 0 || (float) $candidate['confidence'] > 1))) {
                throw new \InvalidArgumentException('geometry_contract_scale_candidate_invalid');
            }
        }
    }

    /** @param array<int, array<string, mixed>> $warnings */
    private static function assertWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            if (! self::isReferenceString($warning['code'])
                || (isset($warning['count']) && (! is_int($warning['count']) || $warning['count'] < 0))) {
                throw new \InvalidArgumentException('geometry_contract_warning_invalid');
            }
            if (array_key_exists('safe_context', $warning)) {
                self::assertWarningContext($warning['safe_context']);
            }
        }
    }

    /** @param array<string, mixed> $text */
    private static function assertTextDetails(array $text): void
    {
        foreach (['handle', 'type', 'layer', 'text', 'layout'] as $field) {
            if ($field === 'text' ? ! is_string($text[$field]) : ! self::isReferenceString($text[$field])) {
                throw new \InvalidArgumentException('geometry_contract_text_invalid');
            }
        }
        self::assertCoordinate($text['position']);
        self::assertOptionalReferences($text, 'geometry_contract_text_invalid');
        if (isset($text['transform'])) {
            self::assertTransform($text['transform']);
        }
        if (isset($text['bbox'])) {
            self::assertBbox($text['bbox']);
        }
    }

    /** @param array<string, mixed> $dimension */
    private static function assertDimensionDetails(array $dimension): void
    {
        foreach (['handle', 'type', 'layer', 'text', 'layout'] as $field) {
            if ($field === 'text' ? ! is_string($dimension[$field]) : ! self::isReferenceString($dimension[$field])) {
                throw new \InvalidArgumentException('geometry_contract_dimension_invalid');
            }
        }
        if (! is_array($dimension['definition_points'] ?? null) || count($dimension['definition_points']) < 2 || count($dimension['definition_points']) > 4) {
            throw new \InvalidArgumentException('geometry_contract_dimension_invalid');
        }
        self::assertCoordinates($dimension['definition_points']);
        self::assertOptionalReferences($dimension, 'geometry_contract_dimension_invalid');
    }

    private static function assertCoordinate(mixed $coordinate): void
    {
        if (! is_array($coordinate) || count($coordinate) !== 2 || ! self::isFiniteNumber($coordinate[0] ?? null) || ! self::isFiniteNumber($coordinate[1] ?? null)) {
            throw new \InvalidArgumentException('geometry_contract_coordinate_invalid');
        }
    }

    /** @param array<int, mixed> $coordinates */
    private static function assertCoordinates(array $coordinates): void
    {
        foreach ($coordinates as $coordinate) {
            self::assertCoordinate($coordinate);
        }
    }

    private static function assertBox(mixed $box): void
    {
        if (! is_array($box) || count($box) !== 4) {
            throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
        }
        self::assertBounds($box);
    }

    private static function assertTransform(mixed $transform): void
    {
        if (! is_array($transform)) {
            throw new \InvalidArgumentException('geometry_contract_transform_invalid');
        }
        if (count($transform) === 6 && array_reduce($transform, static fn (bool $valid, mixed $value): bool => $valid && self::isFiniteNumber($value), true)) {
            return;
        }
        if (self::isMatrix44($transform)) {
            return;
        }
        throw new \InvalidArgumentException('geometry_contract_transform_invalid');
    }

    private static function isMatrix44(mixed $matrix): bool
    {
        if (! is_array($matrix) || count($matrix) !== 4) {
            return false;
        }
        foreach ($matrix as $row) {
            if (! is_array($row) || count($row) !== 4) {
                return false;
            }
            foreach ($row as $value) {
                if (! self::isFiniteNumber($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function isRgba(mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 4) {
            return false;
        }

        return array_reduce($value, static fn (bool $valid, mixed $channel): bool => $valid && is_int($channel) && $channel >= 0 && $channel <= 255, true);
    }

    private static function assertBbox(mixed $bbox): void
    {
        if (! is_array($bbox) || array_diff(array_keys($bbox), ['x', 'y', 'width', 'height']) !== []) {
            throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
        }
        foreach (['x', 'y', 'width', 'height'] as $field) {
            if (! self::isFiniteNumber($bbox[$field] ?? null)) {
                throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
            }
        }
    }

    /** @param array<string, mixed> $item */
    private static function assertOptionalReferences(array $item, string $reason): void
    {
        foreach (['owner', 'block', 'source_operator', 'source_member_handle'] as $field) {
            if (array_key_exists($field, $item) && ! self::isReferenceString($item[$field])) {
                throw new \InvalidArgumentException($reason);
            }
        }
        if (isset($item['source_lineage']) && ! self::isReferenceList($item['source_lineage'])) {
            throw new \InvalidArgumentException($reason);
        }
    }

    /** @param array<string, mixed> $data */
    private static function assertStructuralBudgets(array $data): void
    {
        if (count($data['pages']) > self::MAX_PAGES) {
            throw new \InvalidArgumentException('geometry_contract_pages_limit');
        }
        $nestedReferences = 0;
        $segments = 0;
        $textCharacters = 0;
        foreach ($data['blocks'] as $block) {
            if (is_array($block) && is_array($block['entities'] ?? null)) {
                $nestedReferences += count($block['entities']);
                if ($nestedReferences > self::MAX_NESTED_REFERENCES) {
                    throw new \InvalidArgumentException('geometry_contract_nested_items_limit');
                }
            }
        }
        foreach (['entities', 'texts', 'dimensions'] as $collection) {
            if (count($data[$collection]) > self::MAX_ITEMS) {
                throw new \InvalidArgumentException('geometry_contract_items_limit');
            }
            foreach ($data[$collection] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                if (is_string($item['text'] ?? null)) {
                    $textCharacters += mb_strlen($item['text']);
                    if ($textCharacters > self::MAX_TEXT_CHARACTERS) {
                        throw new \InvalidArgumentException('geometry_contract_text_limit');
                    }
                }
                if (is_array($item['segments'] ?? null)) {
                    $segments += count($item['segments']);
                    if ($segments > self::MAX_SEGMENTS) {
                        throw new \InvalidArgumentException('geometry_contract_segments_limit');
                    }
                }
                foreach (['source_lineage', 'transform_lineage'] as $field) {
                    if (is_array($item[$field] ?? null)) {
                        $nestedReferences += count($item[$field]);
                        if ($nestedReferences > self::MAX_NESTED_REFERENCES) {
                            throw new \InvalidArgumentException('geometry_contract_nested_items_limit');
                        }
                    }
                }
            }
        }
    }

    private static function isReferenceString(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= self::MAX_REFERENCE_LENGTH;
    }

    private static function isReferenceList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $reference) {
            if (! self::isReferenceString($reference)) {
                return false;
            }
        }

        return true;
    }

    private static function assertWarningContext(mixed $context): void
    {
        if (! is_array($context) || $context === [] || array_diff(array_keys($context), ['decoder_counts', 'reconciliation']) !== []) {
            throw new \InvalidArgumentException('geometry_contract_warning_invalid');
        }
        $allowed = [
            'decoder_counts' => ['unsupported', 'skipped', 'unknown'],
            'reconciliation' => ['object_records', 'entity_records', 'represented_records'],
        ];
        foreach ($context as $group => $counts) {
            if (! is_array($counts) || $counts === [] || array_diff(array_keys($counts), $allowed[$group]) !== []) {
                throw new \InvalidArgumentException('geometry_contract_warning_invalid');
            }
            foreach ($counts as $count) {
                if (! is_int($count) || $count < 0 || $count > 10_000_000) {
                    throw new \InvalidArgumentException('geometry_contract_warning_invalid');
                }
            }
        }
    }

    /** @param array<string, mixed> $data */
    private static function countTopLevelCoordinates(array $data): int
    {
        $count = self::countNumericLeaves($data['bounds']);
        foreach ($data['pages'] as $page) {
            $count += self::countNumericLeaves([$page['width'], $page['height'], $page['media_box'], $page['crop_box'], $page['transform']]);
        }
        foreach ($data['scale_candidates'] as $candidate) {
            $count += self::countNumericLeaves([$candidate['value'], $candidate['confidence'] ?? null]);
        }
        foreach ($data['warnings'] as $warning) {
            $count += self::countNumericLeaves($warning['safe_context'] ?? []);
        }

        return $count;
    }
}
