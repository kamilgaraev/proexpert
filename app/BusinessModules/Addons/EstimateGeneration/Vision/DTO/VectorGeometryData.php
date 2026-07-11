<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

final readonly class VectorGeometryData
{
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
        if ($data['schema_version'] !== 1 || ! is_string($data['runtime_version'])
            || ! preg_match('/^sha256:[a-f0-9]{64}$/D', (string) $data['source_fingerprint'])) {
            throw new \InvalidArgumentException('cad_contract_provenance_invalid');
        }
        if (! in_array($data['unit_status'], ['confirmed', 'unknown', 'ambiguous', 'conflicting'], true)) {
            throw new \InvalidArgumentException('cad_contract_unit_invalid');
        }
        foreach (['bounds', 'layers', 'blocks', 'entities', 'texts', 'dimensions', 'pages', 'scale_candidates', 'warnings'] as $field) {
            if (! is_array($data[$field])) {
                throw new \InvalidArgumentException('cad_contract_field_invalid');
            }
        }
        $handles = [];
        foreach ($data['entities'] as $entity) {
            if (! is_array($entity) || ! isset($entity['handle'], $entity['type'], $entity['layer'])
                || array_diff(array_keys($entity), ['handle', 'type', 'layer', 'points', 'center', 'radius', 'start_angle', 'end_angle', 'closed', 'block', 'transform', 'source_lineage', 'layout']) !== []) {
                throw new \InvalidArgumentException('cad_contract_entity_invalid');
            }
            if (isset($handles[$entity['handle']])) {
                throw new \InvalidArgumentException('cad_contract_duplicate_handle');
            }
            $handles[$entity['handle']] = true;
        }

        return new self(
            $data['schema_version'], $data['runtime_version'], $data['source_fingerprint'], $data['source_unit'],
            $data['unit_status'], $data['bounds'], $data['layers'], $data['blocks'], $data['entities'],
            $data['texts'], $data['dimensions'], $data['pages'], $data['scale_candidates'], $data['warnings']
        );
    }
}
