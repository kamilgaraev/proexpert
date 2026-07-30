<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;

final readonly class PlanOneBEvidenceBuilder
{
    public function __construct(
        private string $artifactPath = 'build/reports/plan-1b-completion.json',
    ) {
        if (trim($artifactPath) !== $artifactPath || $artifactPath === '') {
            throw new RuntimeException('plan_one_b_evidence_artifact_path_invalid');
        }
    }

    public function build(
        PlanOneACompletionRef $planOneA,
        array $checks,
        DateTimeImmutable $generatedAt,
    ): array {
        if (! $this->hasExactKeys(
            $checks,
            [
                'repository_revision',
                'gates',
                'ownership',
                'performance_measurements',
                'unresolved_risks',
            ],
        )) {
            throw new \InvalidArgumentException('plan_one_b_evidence_invalid');
        }

        $utc = new DateTimeZone('UTC');
        $document = [
            'schema_version' => '1.0.0',
            'plan_id' => '1b',
            'status' => 'passed',
            'generated_at' => $generatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
            'plan_1a_reference' => [
                'lock_sha256' => $planOneA->lockSha256,
                'evidence_sha256' => $planOneA->evidenceSha256,
                'generated_at' => $planOneA->generatedAt->setTimezone($utc)->format('Y-m-d\TH:i:s\Z'),
                'status' => $planOneA->status,
            ],
            'repository_revision' => $checks['repository_revision'],
            'gates' => $checks['gates'],
            'ownership' => $checks['ownership'],
            'performance_measurements' => $checks['performance_measurements'],
            'unresolved_risks' => $checks['unresolved_risks'],
            'handoff' => [
                'plans_2_and_3' => 'plan_1a_provider_ports_candidate_bindings_only',
                'plan_1c' => 'published_registry_map_and_all_publication_transitions',
                'plan_4' => 'evidence_verification_and_deployment_rollout_only',
                'artifact_path' => 'build/reports/plan-1b-completion.json',
                'digest_algorithm' => 'sha256',
            ],
        ];

        $validator = new PlanOneBEvidenceValidator($planOneA);
        $validator->validate($document);
        $bytes = CanonicalJson::encode($document)."\n";
        $this->writeAtomically($bytes);
        $reread = file_get_contents($this->artifactPath);
        if (! is_string($reread) || ! hash_equals($bytes, $reread)) {
            throw new RuntimeException('plan_one_b_evidence_artifact_reread_failed');
        }

        try {
            $decoded = json_decode($reread, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('plan_one_b_evidence_artifact_reread_failed', 0, $exception);
        }
        if (! is_array($decoded) || CanonicalJson::encode($decoded)."\n" !== $reread) {
            throw new RuntimeException('plan_one_b_evidence_artifact_noncanonical');
        }
        $validator->validate($decoded);

        return $decoded;
    }

    private function writeAtomically(string $bytes): void
    {
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
            if (file_put_contents($temporaryPath, $bytes, LOCK_EX) !== strlen($bytes)
                || ! rename($temporaryPath, $this->artifactPath)) {
                throw new RuntimeException('plan_one_b_evidence_artifact_write_failed');
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        return $actual === $expected;
    }
}
