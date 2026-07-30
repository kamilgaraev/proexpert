<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PlanOneBEvidenceValidator
{
    private const ROOT_KEYS = [
        'schema_version',
        'plan_id',
        'status',
        'generated_at',
        'plan_1a_reference',
        'repository_revision',
        'gates',
        'ownership',
        'performance_measurements',
        'unresolved_risks',
        'handoff',
    ];

    private const REQUIRED_GATES = [
        'plan1a_handoff',
        'ownership_boundary',
        'run_state_machine',
        'run_idempotency',
        'snapshot_identity',
        'snapshot_seal_trust',
        'typed_data_classification',
        'rows_cursor_drill_parity',
        'row_stream_shape',
        'export_state_machine',
        'export_idempotency',
        'dispatch_outbox_atomicity',
        'dispatch_lease_recovery',
        'dispatch_dead_letter',
        'audit_outbox_delivery',
        'current_async_authorization',
        'execution_attempt_leases',
        'renderer_parity',
        'pdf_renderer_budget',
        'streaming_budget',
        'file_service_call_graph',
        's3_version_race',
        'audit_fail_closed',
        'retention_exact_version',
        'action_bindings',
        'error_retryability',
        'run_export_observability',
        'static_analysis',
    ];

    private const PLAN_ONE_A_SYMBOLS = [
        'CandidateReportDefinition',
        'CreateReportExportData',
        'CreateReportRunData',
        'PublishedReportDefinition',
        'ReportCursor',
        'ReportDataProvider',
        'ReportDefinition',
        'ReportDefinitionBinding',
        'ReportDefinitionBindingMap',
        'ReportDownloadLink',
        'ReportDrillDownProvider',
        'ReportExport',
        'ReportPage',
        'ReportQuery',
        'ReportRowQuery',
        'ReportRun',
    ];

    private const PLAN_ONE_B_EVIDENCE_SYMBOLS = [
        'PlanOneACompletionRef',
        'PlanOneACompletionVerifier',
        'PlanOneBEvidenceBuilder',
        'PlanOneBEvidenceValidator',
    ];

    private const EXTERNAL_PLAN_OWNERS = [
        'plan_1c_publication_registry',
        'plan_2_candidate_provider_bindings',
        'plan_3_candidate_provider_bindings',
        'plan_4_evidence_verification_rollout',
    ];

    private const HANDOFF = [
        'plans_2_and_3' => 'plan_1a_provider_ports_candidate_bindings_only',
        'plan_1c' => 'published_registry_map_and_all_publication_transitions',
        'plan_4' => 'evidence_verification_and_deployment_rollout_only',
        'artifact_path' => 'build/reports/plan-1b-completion.json',
        'digest_algorithm' => 'sha256',
    ];

    public function __construct(private PlanOneACompletionRef $verifiedPlanOneA) {}

    public function validate(array $document): void
    {
        if (! $this->hasExactKeys($document, self::ROOT_KEYS)
            || $document['schema_version'] !== '1.0.0'
            || $document['plan_id'] !== '1b'
            || $document['status'] !== 'passed'
            || ! $this->isTimestamp($document['generated_at'])
            || ! $this->isSha1($document['repository_revision'])
            || ! $this->containsNoSubscriptionTelemetry($document)) {
            $this->fail();
        }

        $this->validatePlanOneAReference($document['plan_1a_reference']);
        $this->validateGates($document['gates']);
        $this->validateOwnership($document['ownership']);
        $this->validatePerformance($document['performance_measurements']);
        $this->validateRisks($document['unresolved_risks']);

        if (! is_array($document['handoff'])
            || array_is_list($document['handoff'])
            || ! $this->hasExactKeys($document['handoff'], array_keys(self::HANDOFF))) {
            $this->fail();
        }
        foreach (self::HANDOFF as $key => $value) {
            if (! is_string($document['handoff'][$key]) || $document['handoff'][$key] !== $value) {
                $this->fail();
            }
        }
    }

    private function validatePlanOneAReference(mixed $reference): void
    {
        if (! is_array($reference)
            || array_is_list($reference)
            || ! $this->hasExactKeys($reference, ['lock_sha256', 'evidence_sha256', 'generated_at', 'status'])
            || ! $this->isSha256($reference['lock_sha256'])
            || ! $this->isSha256($reference['evidence_sha256'])
            || ! $this->isTimestamp($reference['generated_at'])
            || $reference['status'] !== 'passed'
            || ! hash_equals($this->verifiedPlanOneA->lockSha256, $reference['lock_sha256'])
            || ! hash_equals($this->verifiedPlanOneA->evidenceSha256, $reference['evidence_sha256'])
            || $reference['generated_at'] !== $this->formatTimestamp($this->verifiedPlanOneA->generatedAt)
            || $reference['status'] !== $this->verifiedPlanOneA->status) {
            $this->fail();
        }
    }

    private function validateGates(mixed $gates): void
    {
        if (! is_array($gates) || ! array_is_list($gates) || count($gates) !== count(self::REQUIRED_GATES)) {
            $this->fail();
        }

        $ids = [];
        foreach ($gates as $gate) {
            if (! is_array($gate)
                || array_is_list($gate)
                || ! $this->hasExactKeys($gate, ['id', 'status', 'command', 'result', 'duration_ms', 'artifacts'])
                || ! is_string($gate['id'])
                || $gate['status'] !== 'passed'
                || ! is_string($gate['command'])
                || trim($gate['command']) !== $gate['command']
                || $gate['command'] === ''
                || $this->isForbiddenGateEvidence($gate['command'])
                || ! is_int($gate['duration_ms'])
                || $gate['duration_ms'] < 0) {
                $this->fail();
            }

            $this->validateGateResult($gate['result']);
            $this->validateArtifacts($gate['artifacts']);
            $ids[] = $gate['id'];
        }

        if ($ids !== self::REQUIRED_GATES || count($ids) !== count(array_unique($ids))) {
            $this->fail();
        }

        if ($gates[0]['artifacts'] !== [
            [
                'name' => 'plan-1a-contract-lock.json',
                'sha256' => $this->verifiedPlanOneA->lockSha256,
            ],
            [
                'name' => 'plan-1a-completion.json',
                'sha256' => $this->verifiedPlanOneA->evidenceSha256,
            ],
        ]) {
            $this->fail();
        }
    }

    private function validateGateResult(mixed $result): void
    {
        if (! is_array($result)
            || array_is_list($result)
            || ! $this->hasExactKeys($result, ['exit_code', 'tests', 'assertions'])
            || $result['exit_code'] !== 0
            || ! is_int($result['tests'])
            || $result['tests'] < 0
            || ! is_int($result['assertions'])
            || $result['assertions'] < 0) {
            $this->fail();
        }
    }

    private function validateArtifacts(mixed $artifacts): void
    {
        if (! is_array($artifacts) || ! array_is_list($artifacts) || $artifacts === []) {
            $this->fail();
        }

        $names = [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)
                || array_is_list($artifact)
                || ! $this->hasExactKeys($artifact, ['name', 'sha256'])
                || ! is_string($artifact['name'])
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $artifact['name']) !== 1
                || $this->isForbiddenGateEvidence($artifact['name'])
                || ! $this->isSha256($artifact['sha256'])) {
                $this->fail();
            }
            $names[] = $artifact['name'];
        }

        if (count($names) !== count(array_unique($names))) {
            $this->fail();
        }
    }

    private function validateOwnership(mixed $ownership): void
    {
        if (! is_array($ownership)
            || array_is_list($ownership)
            || ! $this->hasExactKeys(
                $ownership,
                ['plan_1a_symbols', 'plan_1b_symbols', 'external_plan_owners'],
            )
            || $ownership['plan_1a_symbols'] !== self::PLAN_ONE_A_SYMBOLS
            || $ownership['plan_1b_symbols'] !== self::PLAN_ONE_B_EVIDENCE_SYMBOLS
            || $ownership['external_plan_owners'] !== self::EXTERNAL_PLAN_OWNERS
            || array_intersect($ownership['plan_1a_symbols'], $ownership['plan_1b_symbols']) !== []) {
            $this->fail();
        }
    }

    private function validatePerformance(mixed $measurements): void
    {
        if (! is_array($measurements) || ! array_is_list($measurements) || $measurements === []) {
            $this->fail();
        }

        $ids = [];
        foreach ($measurements as $measurement) {
            if (! is_array($measurement)
                || array_is_list($measurement)
                || ! $this->hasExactKeys($measurement, ['id', 'value', 'unit', 'limit', 'status'])
                || ! is_string($measurement['id'])
                || preg_match('/^[a-z][a-z0-9_]*$/D', $measurement['id']) !== 1
                || ! is_int($measurement['value'])
                || $measurement['value'] < 0
                || ! is_int($measurement['limit'])
                || $measurement['limit'] < 0
                || $measurement['value'] > $measurement['limit']
                || ! in_array($measurement['unit'], ['rows', 'bytes', 'milliseconds'], true)
                || $measurement['status'] !== 'passed') {
                $this->fail();
            }
            $ids[] = $measurement['id'];
        }

        $sortedIds = $ids;
        sort($sortedIds, SORT_STRING);
        if ($ids !== $sortedIds || count($ids) !== count(array_unique($ids))) {
            $this->fail();
        }
    }

    private function validateRisks(mixed $risks): void
    {
        if (! is_array($risks) || ! array_is_list($risks)) {
            $this->fail();
        }

        foreach ($risks as $risk) {
            if (! is_string($risk) || trim($risk) !== $risk || $risk === '') {
                $this->fail();
            }
        }

        $sortedRisks = $risks;
        sort($sortedRisks, SORT_STRING);
        if ($risks !== $sortedRisks || count($risks) !== count(array_unique($risks))) {
            $this->fail();
        }
    }

    private function containsNoSubscriptionTelemetry(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/(?:subscription|telemetry)/i', $value) !== 1;
        }
        if (! is_array($value)) {
            return true;
        }
        foreach ($value as $key => $item) {
            if ((is_string($key) && ! $this->containsNoSubscriptionTelemetry($key))
                || ! $this->containsNoSubscriptionTelemetry($item)) {
                return false;
            }
        }

        return true;
    }

    private function isForbiddenGateEvidence(string $value): bool
    {
        return preg_match('/(?:^|[^a-z0-9])(?:runtime|browser|smoke|artisan|playwright|dusk|selenium|build)(?:$|[^a-z0-9])/i', $value) === 1;
    }

    private function isTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();

        return $date instanceof DateTimeImmutable
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function formatTimestamp(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private function isSha1(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{40}$/D', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }

    private function fail(): never
    {
        throw new InvalidArgumentException('plan_one_b_evidence_invalid');
    }
}
