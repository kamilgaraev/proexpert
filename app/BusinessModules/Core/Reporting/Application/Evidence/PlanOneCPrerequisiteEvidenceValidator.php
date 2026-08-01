<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportEvidenceArtifactDescriptor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPrerequisiteEvidenceBundle;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use RuntimeException;

final readonly class PlanOneCPrerequisiteEvidenceValidator
{
    private const PLAN_ONE_B_GATES = [
        'plan1a_handoff', 'ownership_boundary', 'run_state_machine', 'run_idempotency',
        'snapshot_identity', 'rows_cursor_drill_parity', 'row_stream_shape', 'export_state_machine',
        'export_idempotency', 'renderer_parity', 'pdf_renderer_budget', 'streaming_budget',
        'file_service_call_graph', 's3_version_race', 'audit_fail_closed', 'retention_exact_version',
        'action_bindings', 'error_retryability', 'run_export_observability', 'static_analysis',
    ];

    private const PLAN_ONE_A_MAPPINGS = [
        'contract_lock_sha256' => 'plan-1a-contract-lock',
        'resource_schema_sha256' => 'plan-1a-resource-schema',
        'route_snapshot_sha256' => 'plan-1a-route-snapshot',
        'ci_http_matrices.authorization.artifact_sha256' => 'plan-1a-ci-authorization',
        'ci_http_matrices.malformed_requests.artifact_sha256' => 'plan-1a-ci-malformed',
    ];

    public function __construct(private ?string $repositoryRoot = null) {}

    public function validateBundle(string $manifestPath): ReportPrerequisiteEvidenceBundle
    {
        $manifest = $this->readFile($manifestPath, 'prerequisite_manifest_missing');
        $data = $this->decode($manifest, 'prerequisite_manifest_invalid');
        $this->validateSchema($data, 'docs/reports/contracts/report-prerequisite-artifact-bundle.schema.json', 'prerequisite_manifest_schema_invalid');
        if (($data['schema_version'] ?? null) !== '1.0.0' || !is_array($data['artifacts'] ?? null) || !array_is_list($data['artifacts'])) {
            $this->fail('prerequisite_manifest_invalid');
        }

        $base = realpath(dirname($manifestPath));
        if (!is_string($base) || is_link($base)) {
            $this->fail('prerequisite_manifest_invalid');
        }

        $descriptors = [];
        foreach ($data['artifacts'] as $item) {
            if (!is_array($item) || !$this->exactKeys($item, ['id', 'plan', 'kind', 'relative_path', 'sha256'])
                || !is_string($item['id']) || !is_string($item['plan']) || !is_string($item['kind'])
                || !is_string($item['relative_path']) || !is_string($item['sha256'])) {
                $this->fail('prerequisite_manifest_invalid');
            }
            if (isset($descriptors[$item['id']]) || !$this->validRelativePath($item['relative_path'])) {
                $this->fail('prerequisite_manifest_invalid');
            }
            $path = $this->resolve($base, $item['relative_path']);
            $bytes = $this->readFile($path, 'prerequisite_artifact_missing');
            if (!hash_equals($item['sha256'], hash('sha256', $bytes))) {
                $this->fail('prerequisite_artifact_hash_mismatch');
            }
            $descriptors[$item['id']] = [
                'descriptor' => new ReportEvidenceArtifactDescriptor($item['id'], $item['plan'], $item['kind'], $item['relative_path'], new Sha256Hash($item['sha256'])),
                'bytes' => $bytes,
            ];
        }

        $this->assertExpectedSet($descriptors);
        $planOneA = $this->decode($descriptors['plan-1a-completion']['bytes'], 'prerequisite_plan_1a_invalid');
        $planOneB = $this->decode($descriptors['plan-1b-completion']['bytes'], 'prerequisite_plan_1b_invalid');
        $this->validateSchema($planOneA, 'docs/reports/contracts/plan-1a-completion.schema.json', 'prerequisite_plan_1a_schema_invalid');
        $this->validateSchema($planOneB, 'docs/reports/contracts/plan-1b-evidence.schema.json', 'prerequisite_plan_1b_schema_invalid');
        $this->assertPlanOneA($planOneA, $descriptors);
        $this->assertPlanOneB($planOneB, $descriptors, $planOneA);

        $artifactList = [];
        foreach ($descriptors as $entry) {
            $artifactList[] = $entry['descriptor'];
        }

        return new ReportPrerequisiteEvidenceBundle(
            new Sha256Hash(hash('sha256', $manifest)),
            $artifactList,
            $planOneA,
            $planOneB,
        );
    }

    private function assertExpectedSet(array $descriptors): void
    {
        $expected = ['plan-1a-completion', ...array_values(self::PLAN_ONE_A_MAPPINGS), 'plan-1b-completion'];
        foreach (self::PLAN_ONE_B_GATES as $gate) $expected[] = 'plan-1b:'.$gate;
        $actual = array_keys($descriptors); sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) $this->fail('prerequisite_descriptor_set_invalid');
        foreach (self::PLAN_ONE_A_MAPPINGS as $id) $this->assertDescriptor($descriptors[$id]['descriptor'], '1a', 'nested_artifact', 'artifacts/'.$id.'.json');
        $this->assertDescriptor($descriptors['plan-1a-completion']['descriptor'], '1a', 'completion', 'plan-1a-completion.valid.json');
        $this->assertDescriptor($descriptors['plan-1b-completion']['descriptor'], '1b', 'completion', 'plan-1b-completion.valid.json');
        foreach (self::PLAN_ONE_B_GATES as $gate) $this->assertDescriptor($descriptors['plan-1b:'.$gate]['descriptor'], '1b', 'gate_artifact', 'artifacts/plan-1b-'.str_replace('_', '-', $gate).'.json');
    }

    private function assertPlanOneA(array $document, array $descriptors): void
    {
        if (($document['status'] ?? null) !== 'passed' || !is_string($document['commit_sha'] ?? null)
            || preg_match('/^[a-f0-9]{40}$/D', $document['commit_sha']) !== 1) $this->fail('prerequisite_plan_1a_invalid');
        foreach (['authorization' => [22, 22], 'malformed_requests' => [20, 20]] as $key => [$cases, $passed]) {
            $matrix = $document['ci_http_matrices'][$key] ?? null;
            if (!is_array($matrix) || ($matrix['verification_mode'] ?? null) !== 'hermetic_http'
                || ($matrix['cases'] ?? null) !== $cases || ($matrix['passed'] ?? null) !== $passed) $this->fail('prerequisite_plan_1a_invalid');
        }
        foreach (self::PLAN_ONE_A_MAPPINGS as $field => $id) {
            $value = $this->valueAt($document, explode('.', $field));
            if (!is_string($value) || !hash_equals($value, $descriptors[$id]['descriptor']->sha256->value)) $this->fail('prerequisite_artifact_hash_mismatch');
        }
    }

    private function assertPlanOneB(array $document, array $descriptors, array $planOneA): void
    {
        if (($document['status'] ?? null) !== 'passed' || !is_array($document['gates'] ?? null) || !array_is_list($document['gates'])) $this->fail('prerequisite_plan_1b_invalid');
        $aHash = $descriptors['plan-1a-completion']['descriptor']->sha256->value;
        if (!hash_equals((string)($document['plan_1a_reference']['evidence_sha256'] ?? ''), $aHash)
            || ($document['repository_revision'] ?? null) !== ($planOneA['commit_sha'] ?? null)) $this->fail('prerequisite_plan_1b_invalid');
        $gates = [];
        foreach ($document['gates'] as $gate) {
            if (!is_array($gate) || !is_string($gate['id'] ?? null) || isset($gates[$gate['id']])) $this->fail('prerequisite_plan_1b_invalid');
            $gates[$gate['id']] = $gate;
        }
        foreach (self::PLAN_ONE_B_GATES as $id) {
            if (!isset($gates[$id])) $this->fail('prerequisite_plan_1b_invalid');
            $artifact = $gates[$id]['artifacts'][0] ?? null;
            $digest = $descriptors['plan-1b:'.$id]['descriptor']->sha256->value;
            if (($gates[$id]['status'] ?? null) !== 'passed' || !is_array($artifact) || !hash_equals((string)($artifact['sha256'] ?? ''), $digest)) $this->fail('prerequisite_artifact_hash_mismatch');
        }
    }

    private function validateSchema(array $document, string $relativeSchemaPath, string $error): void
    {
        $schema = $this->decode($this->readFile($this->root().'/'.$relativeSchemaPath, $error), $error);
        if (!(new CompliantValidator())->validate(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
        )->isValid()) $this->fail($error);
    }
    private function resolve(string $base, string $relative): string { $path = realpath($base.'/'.$relative); if (!is_string($path) || is_link($path) || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $base), '/').'/')) $this->fail('prerequisite_artifact_missing'); return $path; }
    private function validRelativePath(string $path): bool { return $path !== '' && !str_contains($path, "\0") && preg_match('#^(?![\\\\/]|[A-Za-z]:)(?!.*(?:^|[\\\\/])\\.\\.(?:[\\\\/]|$))[A-Za-z0-9_.\\-/]+$#D', $path) === 1; }
    private function assertDescriptor(ReportEvidenceArtifactDescriptor $d, string $plan, string $kind, string $path): void { if ($d->plan !== $plan || $d->kind !== $kind || $d->relativePath !== $path) $this->fail('prerequisite_descriptor_set_invalid'); }
    private function readFile(string $path, string $error): string { if (is_link($path) || !is_file($path) || !is_readable($path)) $this->fail($error); $bytes = file_get_contents($path); if (!is_string($bytes)) $this->fail($error); return $bytes; }
    private function decode(string $bytes, string $error): array { try { $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { $this->fail($error); } if (!is_array($value) || array_is_list($value)) $this->fail($error); return $value; }
    private function exactKeys(array $value, array $expected): bool { $keys = array_keys($value); sort($keys); sort($expected); return $keys === $expected; }
    private function valueAt(array $value, array $segments): mixed { foreach ($segments as $segment) { if (!is_array($value) || !array_key_exists($segment, $value)) return null; $value = $value[$segment]; } return $value; }
    private function root(): string { $root = realpath($this->repositoryRoot ?? getcwd()); if (!is_string($root)) $this->fail('prerequisite_repository_root_invalid'); return str_replace('\\', '/', $root); }
    private function fail(string $message): never { throw new RuntimeException($message); }
}
