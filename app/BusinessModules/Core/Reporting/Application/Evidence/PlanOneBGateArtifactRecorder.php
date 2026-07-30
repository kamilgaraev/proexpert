<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use InvalidArgumentException;
use JsonException;

final class PlanOneBGateArtifactRecorder
{
    private const RECORD_KINDS = [
        'contract_json' => 'contract_case',
        'architecture_json' => 'architecture_rule',
        'unit_json' => 'unit_case',
        'postgresql_json' => 'postgresql_case',
        'cryptographic_json' => 'cryptographic_case',
        'authorization_json' => 'authorization_case',
        'queue_json' => 'queue_case',
        'parity_json' => 'parity_case',
        'performance_json' => 'performance_case',
        's3_json' => 's3_case',
        'observability_json' => 'observability_case',
        'phpstan_json' => 'static_analysis_case',
    ];

    public function record(array $definition, array $processResult, string $repositoryRevision): array
    {
        $this->assertExactKeys($definition, [
            'artifact_id',
            'artifact_type',
            'producer',
            'gate_id',
            'command',
            'required_checks',
            'measurements',
        ]);
        $this->assertExactKeys($processResult, [
            'command',
            'exit_code',
            'started_at',
            'finished_at',
            'duration_ms',
            'stdout',
            'stderr',
        ]);
        if (! isset(self::RECORD_KINDS[$definition['artifact_type']])
            || $processResult['command'] !== $definition['command']
            || $processResult['exit_code'] !== 0
            || ! is_int($processResult['duration_ms'])
            || $processResult['duration_ms'] < 0
            || ! is_string($processResult['stdout'])
            || ! is_string($processResult['stderr'])
            || ! $this->isTimestamp($processResult['started_at'])
            || ! $this->isTimestamp($processResult['finished_at'])) {
            $this->fail();
        }

        try {
            $parsed = json_decode($processResult['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail();
        }
        if (! is_array($parsed) || array_is_list($parsed)) {
            $this->fail();
        }
        $this->assertExactKeys($parsed, ['tests', 'assertions', 'cases']);
        if (! is_int($parsed['tests']) || $parsed['tests'] < 0
            || ! is_int($parsed['assertions']) || $parsed['assertions'] < 1
            || ! is_array($parsed['cases']) || ! array_is_list($parsed['cases'])
            || count($parsed['cases']) !== count($definition['required_checks'])) {
            $this->fail();
        }

        $records = [];
        foreach ($definition['required_checks'] as $index => $check) {
            $case = $parsed['cases'][$index];
            if (! is_array($case) || array_is_list($case)) {
                $this->fail();
            }
            $this->assertExactKeys($case, ['id', 'status', 'tests', 'assertions']);
            if ($case['id'] !== $check || $case['status'] !== 'passed'
                || ! is_int($case['tests']) || $case['tests'] < 1
                || ! is_int($case['assertions']) || $case['assertions'] < 1) {
                $this->fail();
            }
            $records[] = [
                'id' => $case['id'],
                'kind' => self::RECORD_KINDS[$definition['artifact_type']],
                'status' => $case['status'],
                'tests' => $case['tests'],
                'assertions' => $case['assertions'],
            ];
        }

        return [
            'schema_version' => '1.0.0',
            'evidence_scope' => 'ci',
            'artifact_id' => $definition['artifact_id'],
            'artifact_type' => $definition['artifact_type'],
            'repository_revision' => $repositoryRevision,
            'producer' => $definition['producer'],
            'process' => [
                'command' => $processResult['command'],
                'exit_code' => 0,
                'started_at' => $processResult['started_at'],
                'finished_at' => $processResult['finished_at'],
                'duration_ms' => $processResult['duration_ms'],
                'stdout_sha256' => hash('sha256', $processResult['stdout']),
                'stderr_sha256' => hash('sha256', $processResult['stderr']),
            ],
            'gate' => [
                'id' => $definition['gate_id'],
                'status' => 'passed',
                'command' => $definition['command'],
                'result' => [
                    'exit_code' => 0,
                    'tests' => $parsed['tests'],
                    'assertions' => $parsed['assertions'],
                    'required_checks' => $definition['required_checks'],
                ],
                'duration_ms' => $processResult['duration_ms'],
                'measurements' => $definition['measurements'],
            ],
            'records' => $records,
        ];
    }

    private function assertExactKeys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            $this->fail();
        }
    }

    private function isTimestamp(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $value) === 1;
    }

    private function fail(): never
    {
        throw new InvalidArgumentException('plan_one_b_gate_process_result_invalid');
    }
}
