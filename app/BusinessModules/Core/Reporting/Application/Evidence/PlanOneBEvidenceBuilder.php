<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class PlanOneBEvidenceBuilder
{
    private string $artifactPath;

    private Closure $atomicRename;

    private string $repositoryRoot;

    public function __construct(
        string $artifactPath = 'build/reports/plan-1b-completion.json',
        ?Closure $atomicRename = null,
        ?string $repositoryRoot = null,
    ) {
        if (trim($artifactPath) !== $artifactPath || $artifactPath === '') {
            throw new RuntimeException('plan_one_b_evidence_artifact_path_invalid');
        }
        $this->artifactPath = $artifactPath;
        $this->atomicRename = $atomicRename ?? static fn (string $temporary, string $final): bool => rename($temporary, $final);
        $resolvedRoot = realpath($repositoryRoot ?? getcwd());
        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot)) {
            throw new RuntimeException('plan_one_b_evidence_repository_root_invalid');
        }
        $this->repositoryRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
    }

    public function build(
        PlanOneACompletionRef $planOneA,
        array $checks,
        DateTimeImmutable $generatedAt,
    ): array {
        if (! $this->hasExactKeys(
            $checks,
            ['repository_revision', 'gate_artifacts', 'ownership', 'unresolved_risks'],
        )
            || ! is_string($checks['repository_revision'])
            || preg_match('/^[a-f0-9]{40}$/D', $checks['repository_revision']) !== 1
            || ! is_array($checks['gate_artifacts'])
            || ! array_is_list($checks['gate_artifacts'])) {
            throw new \InvalidArgumentException('plan_one_b_evidence_invalid');
        }

        $validator = new PlanOneBEvidenceValidator($planOneA);
        $gates = [];
        $performance = [];
        foreach ($checks['gate_artifacts'] as $artifactInput) {
            [$gate, $artifact] = $this->readGateArtifact(
                $artifactInput,
                $checks['repository_revision'],
                $validator,
            );
            $gate['artifacts'] = [$artifact];
            $gates[] = $gate;
            array_push($performance, ...$gate['measurements']);
        }

        $utc = new DateTimeZone('UTC');
        $document = [
            'schema_version' => '1.0.0',
            'plan_id' => '1b',
            'evidence_scope' => 'ci',
            'status' => 'passed',
            'generated_at' => $generatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'plan_1a_reference' => [
                'lock_sha256' => $planOneA->lockSha256,
                'evidence_sha256' => $planOneA->evidenceSha256,
                'generated_at' => $planOneA->generatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                'status' => $planOneA->status,
            ],
            'repository_revision' => $checks['repository_revision'],
            'gates' => $gates,
            'ownership' => $checks['ownership'],
            'performance_measurements' => $performance,
            'unresolved_risks' => $checks['unresolved_risks'],
            'handoff' => [
                'plans_2_and_3' => 'plan_1a_provider_ports_candidate_bindings_only',
                'plan_1c' => 'published_registry_map_and_all_publication_transitions',
                'plan_4' => 'evidence_verification_and_deployment_rollout_only',
                'artifact_path' => 'build/reports/plan-1b-completion.json',
                'digest_algorithm' => 'sha256',
            ],
        ];

        $validator->validate($document);
        $bytes = CanonicalJson::encode($document)."\n";
        $expectedSha256 = hash('sha256', $bytes);
        $this->publishAtomically($bytes, $expectedSha256, $validator);

        return $this->validatedDocument($this->artifactPath, $bytes, $expectedSha256, $validator);
    }

    private function readGateArtifact(
        mixed $artifactInput,
        string $repositoryRevision,
        PlanOneBEvidenceValidator $validator,
    ): array {
        if (! is_array($artifactInput)
            || array_is_list($artifactInput)
            || ! $this->hasExactKeys($artifactInput, ['path', 'sha256'])
            || ! is_string($artifactInput['path'])
            || preg_match('#^build/reports/gates/[a-z0-9_]+\.json$#D', $artifactInput['path']) !== 1
            || ! is_string($artifactInput['sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $artifactInput['sha256']) !== 1
        ) {
            throw new \InvalidArgumentException('plan_one_b_evidence_invalid');
        }
        $absolutePath = realpath($this->repositoryRoot.'/'.$artifactInput['path']);
        if (! is_string($absolutePath)
            || ! str_starts_with(str_replace('\\', '/', $absolutePath), $this->repositoryRoot.'/build/reports/gates/')
            || ! is_file($absolutePath)
            || is_link($absolutePath)) {
            throw new \InvalidArgumentException('plan_one_b_evidence_invalid');
        }
        $bytes = file_get_contents($absolutePath);
        if (! is_string($bytes)
            || ! hash_equals($artifactInput['sha256'], hash('sha256', $bytes))) {
            throw new \InvalidArgumentException('plan_one_b_evidence_invalid');
        }
        $envelope = $this->decode($bytes);
        $gate = $validator->validateGateArtifactEnvelope(
            $envelope,
            $repositoryRevision,
            $artifactInput['path'],
        );

        return [
            $gate,
            [
                'id' => $envelope['artifact_id'],
                'type' => $envelope['artifact_type'],
                'sha256' => $artifactInput['sha256'],
                'repository_revision' => $repositoryRevision,
            ],
        ];
    }

    private function publishAtomically(
        string $bytes,
        string $expectedSha256,
        PlanOneBEvidenceValidator $validator,
    ): void {
        $directory = dirname($this->artifactPath);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('plan_one_b_evidence_artifact_directory_failed');
        }
        if (is_link($this->artifactPath) || (file_exists($this->artifactPath) && ! is_file($this->artifactPath))) {
            throw new RuntimeException('plan_one_b_evidence_artifact_path_invalid');
        }

        $temporaryPath = tempnam($directory, '.plan-1b-evidence-');
        if (! is_string($temporaryPath)) {
            throw new RuntimeException('plan_one_b_evidence_artifact_write_failed');
        }

        try {
            if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('plan_one_b_evidence_artifact_write_failed');
            }
            $this->validatedDocument($temporaryPath, $bytes, $expectedSha256, $validator);
            if (! ($this->atomicRename)($temporaryPath, $this->artifactPath)) {
                throw new RuntimeException('plan_one_b_evidence_artifact_write_failed');
            }
            try {
                $this->validatedDocument($this->artifactPath, $bytes, $expectedSha256, $validator);
            } catch (Throwable $exception) {
                if (is_file($this->artifactPath)) {
                    unlink($this->artifactPath);
                }
                throw new RuntimeException('plan_one_b_evidence_artifact_final_mismatch', 0, $exception);
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function validatedDocument(
        string $path,
        string $expectedBytes,
        string $expectedSha256,
        PlanOneBEvidenceValidator $validator,
    ): array {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)
            || ! hash_equals($expectedBytes, $bytes)
            || ! hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('plan_one_b_evidence_artifact_reread_failed');
        }
        $document = $this->decode($bytes);
        if (CanonicalJson::encode($document)."\n" !== $bytes) {
            throw new RuntimeException('plan_one_b_evidence_artifact_noncanonical');
        }
        $validator->validate($document);

        return $document;
    }

    private function decode(string $bytes): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('plan_one_b_evidence_artifact_invalid_json', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('plan_one_b_evidence_artifact_invalid_json');
        }

        return $decoded;
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
