<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

final readonly class VectorGeometryData
{
    private const MAX_ITEMS = 100_000;

    private const MAX_POINTS = 500_000;

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
        self::assertDepth($data);
        self::assertBounds($data['bounds']);
        self::assertCollection($data['layers'], ['name', 'visible'], ['name', 'visible'], 'layer');
        self::assertCollection($data['blocks'], ['name', 'handle', 'owner', 'entities'], ['name', 'handle', 'owner', 'entities'], 'block');
        self::assertCollection($data['entities'], ['handle', 'type', 'layer'], ['handle', 'type', 'layer', 'points', 'segments', 'center', 'radius', 'start_angle', 'end_angle', 'closed', 'block', 'transform', 'transform_lineage', 'source_lineage', 'layout', 'owner', 'bbox', 'style'], 'entity');
        self::assertCollection($data['texts'], ['handle', 'type', 'layer', 'text', 'position', 'layout'], ['handle', 'type', 'layer', 'text', 'position', 'layout', 'source_operator', 'transform', 'owner', 'bbox'], 'text');
        self::assertCollection($data['dimensions'], ['handle', 'type', 'layer', 'text', 'layout'], ['handle', 'type', 'layer', 'text', 'layout', 'definition_points', 'transform', 'owner'], 'dimension');
        self::assertCollection($data['pages'], ['page_number', 'width', 'height', 'rotation', 'media_box', 'crop_box', 'transform', 'classification'], ['page_number', 'width', 'height', 'rotation', 'media_box', 'crop_box', 'transform', 'classification'], 'page');
        self::assertCollection($data['scale_candidates'], ['value', 'source'], ['value', 'source', 'confidence'], 'scale_candidate');
        self::assertCollection($data['warnings'], ['code'], ['code', 'count', 'safe_context'], 'warning');
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
                if (! is_string($handle) || $handle === '' || isset($handles[$handle])) {
                    throw new \InvalidArgumentException('geometry_contract_duplicate_handle');
                }
                $handles[$handle] = true;
                if (isset($item['text']) && (! is_string($item['text']) || mb_strlen($item['text']) > self::MAX_TEXT_LENGTH)) {
                    throw new \InvalidArgumentException('geometry_contract_text_invalid');
                }
                $pointCount += self::countPoints($item);
                if ($collection === 'entities') {
                    self::assertEntityDetails($item);
                }
            }
        }
        if ($pointCount > self::MAX_POINTS) {
            throw new \InvalidArgumentException('geometry_contract_points_limit');
        }

        return new self(
            $data['schema_version'], $data['runtime_version'], $data['source_fingerprint'], $data['source_unit'],
            $data['unit_status'], $data['bounds'], $data['layers'], $data['blocks'], $data['entities'],
            $data['texts'], $data['dimensions'], $data['pages'], $data['scale_candidates'], $data['warnings']
        );
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
        if (count($bounds) !== 4 || ! is_numeric($bounds[0]) || ! is_numeric($bounds[1]) || ! is_numeric($bounds[2]) || ! is_numeric($bounds[3])) {
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
    private static function countPoints(array $item): int
    {
        $count = is_array($item['points'] ?? null) ? count($item['points']) : 0;
        if (is_array($item['segments'] ?? null)) {
            foreach ($item['segments'] as $segment) {
                $count += is_array($segment['points'] ?? null) ? count($segment['points']) : 0;
            }
        }

        return $count;
    }

    /** @param array<string, mixed> $entity */
    private static function assertEntityDetails(array $entity): void
    {
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
            }
        }
        if (isset($entity['style'])) {
            $allowed = ['fill_mode', 'stroke', 'fill_rgba', 'stroke_rgba', 'stroke_width', 'line_cap', 'line_join'];
            if (! is_array($entity['style']) || array_diff(array_keys($entity['style']), $allowed) !== []) {
                throw new \InvalidArgumentException('geometry_contract_style_invalid');
            }
        }
        if (isset($entity['bbox'])) {
            if (! is_array($entity['bbox']) || array_diff(array_keys($entity['bbox']), ['x', 'y', 'width', 'height']) !== []) {
                throw new \InvalidArgumentException('geometry_contract_bounds_invalid');
            }
        }
    }
}
