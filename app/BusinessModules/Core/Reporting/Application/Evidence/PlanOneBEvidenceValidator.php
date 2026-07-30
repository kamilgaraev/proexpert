<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PlanOneBEvidenceValidator
{
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
        'PlanOneBGateArtifactRecorder',
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
        if (! $this->hasExactKeys($document, [
            'schema_version',
            'plan_id',
            'evidence_scope',
            'status',
            'generated_at',
            'plan_1a_reference',
            'repository_revision',
            'gates',
            'ownership',
            'performance_measurements',
            'unresolved_risks',
            'handoff',
        ])
            || $document['schema_version'] !== '1.0.0'
            || $document['plan_id'] !== '1b'
            || ! in_array($document['evidence_scope'], ['fixture', 'ci'], true)
            || $document['status'] !== 'passed'
            || ! $this->isTimestamp($document['generated_at'])
            || ! $this->isSha1($document['repository_revision'])
            || ! $this->containsNoSubscriptionTelemetry($document)) {
            $this->fail();
        }

        $this->validatePlanOneAReference($document['plan_1a_reference']);
        $measurements = $this->validateGates($document['gates'], $document['repository_revision']);
        if (! is_array($document['performance_measurements'])
            || CanonicalJson::encode($document['performance_measurements']) !== CanonicalJson::encode($measurements)) {
            $this->fail();
        }
        $this->validateOwnership($document['ownership']);
        $this->validateRisks($document['unresolved_risks']);
        $this->validateHandoff($document['handoff']);
    }

    public function validateGateArtifactEnvelope(
        array $envelope,
        string $repositoryRevision,
        string $sourcePath,
    ): array {
        if (! $this->hasExactKeys($envelope, [
            'schema_version',
            'evidence_scope',
            'artifact_id',
            'artifact_type',
            'repository_revision',
            'producer',
            'process',
            'gate',
            'records',
        ])
            || $envelope['schema_version'] !== '1.0.0'
            || $envelope['evidence_scope'] !== 'ci'
            || $envelope['repository_revision'] !== $repositoryRevision
            || ! is_array($envelope['gate'])
            || array_is_list($envelope['gate'])
            || ! is_string($envelope['gate']['id'] ?? null)
            || ! isset(PlanOneBGateArtifactRecorder::definitions()[$envelope['gate']['id']])) {
            $this->fail();
        }

        $gateId = $envelope['gate']['id'];
        $definition = PlanOneBGateArtifactRecorder::definition($gateId);
        if ($envelope['artifact_id'] !== $definition['artifact_id']
            || $envelope['artifact_type'] !== $definition['artifact_type']
            || $sourcePath !== $definition['producer']['artifact_path']
            || ! is_array($envelope['producer'])
            || array_is_list($envelope['producer'])
            || CanonicalJson::encode($envelope['producer']) !== CanonicalJson::encode($definition['producer'])) {
            $this->fail();
        }

        $this->validateArtifactGate(
            $envelope['gate'],
            $gateId,
            $definition['required_checks'],
        );
        $this->validateProcess(
            $envelope['process'],
            $definition['command'],
            $envelope['gate']['duration_ms'],
            $definition['measurement_specs'] !== [],
        );
        $this->validateRecords($envelope['records'], $definition);

        return $envelope['gate'];
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

    private function validateGates(mixed $gates, string $repositoryRevision): array
    {
        $definitions = PlanOneBGateArtifactRecorder::definitions();
        if (! is_array($gates) || ! array_is_list($gates) || count($gates) !== count($definitions)) {
            $this->fail();
        }

        $allMeasurements = [];
        $index = 0;
        foreach ($definitions as $gateId => $definition) {
            $gate = $gates[$index];
            if (! is_array($gate)
                || array_is_list($gate)
                || ! $this->hasExactKeys($gate, ['id', 'status', 'command', 'result', 'duration_ms', 'artifacts', 'measurements'])
                || $gate['id'] !== $gateId
                || $gate['status'] !== 'passed'
                || $gate['command'] !== $definition['command']
                || ! is_int($gate['duration_ms'])
                || $gate['duration_ms'] < 0) {
                $this->fail();
            }
            $this->validateResult($gate['result'], $definition['required_checks']);
            $this->validateArtifact(
                $gate['artifacts'],
                $gateId,
                $definition['artifact_type'],
                $repositoryRevision,
            );
            $gateMeasurements = $this->validateMeasurements($gate['measurements'], $gateId);
            array_push($allMeasurements, ...$gateMeasurements);
            $index++;
        }

        return $allMeasurements;
    }

    private function validateResult(mixed $result, array $requiredChecks): void
    {
        if (! is_array($result)
            || array_is_list($result)
            || ! $this->hasExactKeys($result, ['exit_code', 'tests', 'assertions', 'required_checks'])
            || $result['exit_code'] !== 0
            || ! is_int($result['tests'])
            || $result['tests'] < 0
            || ! is_int($result['assertions'])
            || $result['assertions'] < 0
            || $result['required_checks'] !== $requiredChecks) {
            $this->fail();
        }
    }

    private function validateArtifact(
        mixed $artifacts,
        string $gateId,
        string $artifactType,
        string $repositoryRevision,
    ): void {
        if (! is_array($artifacts)
            || ! array_is_list($artifacts)
            || count($artifacts) !== 1
            || ! is_array($artifacts[0])
            || array_is_list($artifacts[0])
            || ! $this->hasExactKeys($artifacts[0], ['id', 'type', 'sha256', 'repository_revision'])
            || $artifacts[0]['id'] !== 'plan1b.gate.'.$gateId
            || $artifacts[0]['type'] !== $artifactType
            || ! $this->isSha256($artifacts[0]['sha256'])
            || $artifacts[0]['repository_revision'] !== $repositoryRevision) {
            $this->fail();
        }
    }

    private function validateMeasurements(mixed $measurements, string $gateId): array
    {
        $specs = PlanOneBGateArtifactRecorder::definition($gateId)['measurement_specs'];
        if (! is_array($measurements) || ! array_is_list($measurements) || count($measurements) !== count($specs)) {
            $this->fail();
        }
        foreach ($specs as $index => [$id, $unit, $limit]) {
            $measurement = $measurements[$index];
            if (! is_array($measurement)
                || array_is_list($measurement)
                || ! $this->hasExactKeys($measurement, ['id', 'value', 'unit', 'limit', 'status'])
                || $measurement['id'] !== $id
                || ! is_int($measurement['value'])
                || $measurement['value'] < 0
                || $measurement['unit'] !== $unit
                || $measurement['limit'] !== $limit
                || $measurement['value'] > $limit
                || $measurement['status'] !== 'passed') {
                $this->fail();
            }
        }

        return $measurements;
    }

    private function validateOwnership(mixed $ownership): void
    {
        if (! is_array($ownership)
            || array_is_list($ownership)
            || ! $this->hasExactKeys($ownership, ['plan_1a_symbols', 'plan_1b_symbols', 'external_plan_owners'])
            || $ownership['plan_1a_symbols'] !== self::PLAN_ONE_A_SYMBOLS
            || $ownership['plan_1b_symbols'] !== self::PLAN_ONE_B_EVIDENCE_SYMBOLS
            || $ownership['external_plan_owners'] !== self::EXTERNAL_PLAN_OWNERS
            || array_intersect($ownership['plan_1a_symbols'], $ownership['plan_1b_symbols']) !== []) {
            $this->fail();
        }
    }

    private function validateRisks(mixed $risks): void
    {
        if (! is_array($risks) || ! array_is_list($risks)) {
            $this->fail();
        }
        foreach ($risks as $risk) {
            if (! is_string($risk)
                || trim($risk) !== $risk
                || $risk === ''
                || preg_match('/(?:subscription|telemetry)/i', $risk) === 1) {
                $this->fail();
            }
        }
        if (count($risks) !== count(array_unique($risks))) {
            $this->fail();
        }
    }

    private function validateHandoff(mixed $handoff): void
    {
        if (! is_array($handoff)
            || array_is_list($handoff)
            || ! $this->hasExactKeys($handoff, array_keys(self::HANDOFF))) {
            $this->fail();
        }
        foreach (self::HANDOFF as $key => $value) {
            if ($handoff[$key] !== $value) {
                $this->fail();
            }
        }
    }

    private function validateArtifactGate(
        mixed $gate,
        string $gateId,
        array $requiredChecks,
    ): void {
        $definition = PlanOneBGateArtifactRecorder::definition($gateId);
        if (! is_array($gate)
            || array_is_list($gate)
            || ! $this->hasExactKeys($gate, ['id', 'status', 'command', 'result', 'duration_ms', 'measurements'])
            || $gate['id'] !== $gateId
            || $gate['status'] !== 'passed'
            || $gate['command'] !== $definition['command']
            || ! is_int($gate['duration_ms'])
            || $gate['duration_ms'] < 0) {
            $this->fail();
        }
        $this->validateResult($gate['result'], $requiredChecks);
        $this->validateMeasurements($gate['measurements'], $gateId);
    }

    private function validateRecords(mixed $records, array $definition): void
    {
        $requiredChecks = $definition['required_checks'];
        if (! is_array($records) || ! array_is_list($records) || count($records) !== count($requiredChecks)) {
            $this->fail();
        }
        foreach ($requiredChecks as $index => $check) {
            $record = $records[$index];
            if (! is_array($record)
                || array_is_list($record)
                || ! $this->hasExactKeys($record, ['id', 'kind', 'status', 'tests', 'assertions', 'suites'])
                || $record['id'] !== $check
                || $record['kind'] !== $definition['record_kind']
                || $record['status'] !== 'passed'
                || ! is_int($record['tests'])
                || $record['tests'] < 1
                || ! is_int($record['assertions'])
                || $record['assertions'] < 1
                || $record['suites'] !== $definition['check_suites'][$check]) {
                $this->fail();
            }
        }
    }

    private function validateProcess(
        mixed $process,
        string $command,
        int $durationMs,
        bool $requiresMeasurements,
    ): void {
        if (! is_array($process)
            || array_is_list($process)
            || ! $this->hasExactKeys($process, [
                'command',
                'exit_code',
                'started_at',
                'finished_at',
                'duration_ms',
                'stdout_sha256',
                'stderr_sha256',
                'result_artifact_sha256',
                'measurement_artifact_sha256',
            ])
            || $process['command'] !== $command
            || $process['exit_code'] !== 0
            || $process['duration_ms'] !== $durationMs
            || ! $this->isTimestamp($process['started_at'])
            || ! $this->isTimestamp($process['finished_at'])
            || ! $this->isSha256($process['stdout_sha256'])
            || ! $this->isSha256($process['stderr_sha256'])
            || ! $this->isSha256($process['result_artifact_sha256'])
            || ($requiresMeasurements
                ? ! $this->isSha256($process['measurement_artifact_sha256'])
                : $process['measurement_artifact_sha256'] !== null)) {
            $this->fail();
        }
    }

    private function containsNoSubscriptionTelemetry(mixed $value): bool
    {
        if (is_string($value)) {
            return preg_match('/subscription/i', $value) !== 1;
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

    private function isTimestamp(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
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
