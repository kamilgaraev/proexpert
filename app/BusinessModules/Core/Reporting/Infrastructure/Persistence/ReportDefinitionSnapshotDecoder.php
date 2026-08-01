<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

final class ReportDefinitionSnapshotDecoder
{
    public const LEGACY_SCHEMA = 'report_definition_snapshot.v1';

    public const CURRENT_SCHEMA = 'report_definition_snapshot.v2';

    private const LEGACY_KEYS = [
        'code', 'definition_hash', 'contract_version', 'formula_version',
        'source_schema_version', 'renderer_version', 'filters', 'columns',
        'sorts', 'formats', 'permission_policy', 'snapshot_classification',
        'output_classification', 'publication_readiness',
        'supports_subscriptions',
    ];

    private const CURRENT_KEYS = [
        ...self::LEGACY_KEYS,
        'snapshot_schema', 'source_module', 'core_access_mode',
    ];

    /** @return array{schema:string,payload:array} */
    public function decode(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new \InvalidArgumentException('report_definition_snapshot_shape_invalid');
        }

        if ($this->hasExactKeys($value, self::CURRENT_KEYS)) {
            if (($value['snapshot_schema'] ?? null) !== self::CURRENT_SCHEMA) {
                throw new \InvalidArgumentException('report_definition_snapshot_schema_invalid');
            }

            return ['schema' => self::CURRENT_SCHEMA, 'payload' => $value];
        }

        if ($this->hasExactKeys($value, self::LEGACY_KEYS)) {
            return [
                'schema' => self::LEGACY_SCHEMA,
                'payload' => [
                    ...$value,
                    'snapshot_schema' => self::LEGACY_SCHEMA,
                    'source_module' => 'reports',
                    'core_access_mode' => 'reporting_workspace',
                ],
            ];
        }

        throw new \InvalidArgumentException('report_definition_snapshot_schema_invalid');
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
