<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPrerequisiteEvidenceBundle;
use App\BusinessModules\Core\Reporting\Domain\DTO\TrackedPlanDocument;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Opis\JsonSchema\CompliantValidator;
use RuntimeException;

final readonly class PlanOneCPlatformEvidenceBuilder
{
    public const PLAN_ONE_B_SHA256 = '58f865ed19b1f040057a37b72dfc52a1822a2925416a1fea3ecc30ee50d4c626';

    public function __construct(private ?string $repositoryRoot = null) {}

    /** @return array<string, mixed> */
    public function build(
        ReportPrerequisiteEvidenceBundle $bundle,
        TrackedPlanDocument $planOneB,
        TrackedPlanDocument $planOneC,
        string $repositoryCommit,
        DateTimeImmutable $generatedAt,
        array $platformEvidence,
    ): array {
        if (preg_match('/^[a-f0-9]{40}$/D', $repositoryCommit) !== 1
            || $planOneB->commitSha !== $repositoryCommit
            || $planOneC->commitSha !== $repositoryCommit
            || $planOneB->bytesHash->value !== self::PLAN_ONE_B_SHA256) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
        if (array_diff(array_keys($platformEvidence), ['published_count', 'binding_count', 'unresolved_risks']) !== []) throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        $artifacts = [];
        foreach ($bundle->artifacts as $artifact) $artifacts[$artifact->id] = $artifact->sha256->value;
        $document = [
            'schema_version' => '1.0.0',
            'plan_id' => '1c',
            'status' => 'platform_passed',
            'repository_commit' => $repositoryCommit,
            'generated_at' => $generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z'),
            'plan_1c_lock_sha256' => $this->sha256('docs/reports/contracts/plan-1c-contract-lock.json'),
            'plan_1a_completion_sha256' => $artifacts['plan-1a-completion'] ?? null,
            'plan_1a_artifact_hashes' => [
                'contract_lock' => $artifacts['plan-1a-contract-lock'] ?? null,
                'resource_schema' => $artifacts['plan-1a-resource-schema'] ?? null,
                'route_snapshot' => $artifacts['plan-1a-route-snapshot'] ?? null,
                'ci_authorization' => $artifacts['plan-1a-ci-authorization'] ?? null,
                'ci_malformed' => $artifacts['plan-1a-ci-malformed'] ?? null,
            ],
            'plan_1b_completion_sha256' => $artifacts['plan-1b-completion'] ?? null,
            'plan_1b_gate_hashes' => array_combine(self::gateIds(), array_map(static fn (string $gate): ?string => $artifacts['plan-1b:'.$gate] ?? null, self::gateIds())),
            'plan_1b_plan_sha256' => self::PLAN_ONE_B_SHA256,
            'plan_1b_document_sha256' => $planOneB->bytesHash->value,
            'plan_1c_document_sha256' => $planOneC->bytesHash->value,
            'prerequisite_bundle_sha256' => $bundle->manifestHash->value,
            'published_count' => $platformEvidence['published_count'] ?? 0,
            'binding_count' => $platformEvidence['binding_count'] ?? 0,
            'unresolved_risks' => $platformEvidence['unresolved_risks'] ?? [],
        ];
        $this->validate($document);

        return $document;
    }

    /** @return array<string, mixed> */
    public function publish(string $path, array $document): array
    {
        $this->validate($document);
        $bytes = CanonicalJson::encode($document)."\n";
        $directory = dirname($path);
        if ((!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) || is_link($path)) {
            throw new RuntimeException('plan_one_c_platform_evidence_write_failed');
        }
        $temporary = tempnam($directory, '.plan-1c-platform-');
        if (!is_string($temporary)) throw new RuntimeException('plan_one_c_platform_evidence_write_failed');
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $path)) {
                throw new RuntimeException('plan_one_c_platform_evidence_write_failed');
            }
            $reread = file_get_contents($path);
            if (!is_string($reread) || !hash_equals($bytes, $reread) || CanonicalJson::encode($this->decode($reread))."\n" !== $reread) {
                throw new RuntimeException('plan_one_c_platform_evidence_reread_failed');
            }
            $result = $this->decode($reread);
            $this->validate($result);
            return $result;
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }

    private function validate(array $document): void
    {
        $schema = $this->decode($this->read($this->root().'/docs/reports/contracts/plan-1c-platform-completion.schema.json'));
        try {
            $valid = (new CompliantValidator())->validate(
                json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
                json_decode(json_encode($schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            )->isValid();
        } catch (JsonException) {
            $valid = false;
        }
        if (!$valid || ($document['status'] ?? null) !== 'platform_passed'
            || ($document['published_count'] ?? null) !== ($document['binding_count'] ?? null)
            || !is_array($document['unresolved_risks'] ?? null) || $document['unresolved_risks'] !== []) {
            throw new RuntimeException('plan_one_c_platform_evidence_invalid');
        }
    }

    /** @return list<string> */
    private static function gateIds(): array
    {
        return ['plan1a_handoff', 'ownership_boundary', 'run_state_machine', 'run_idempotency', 'snapshot_identity', 'rows_cursor_drill_parity', 'row_stream_shape', 'export_state_machine', 'export_idempotency', 'renderer_parity', 'pdf_renderer_budget', 'streaming_budget', 'file_service_call_graph', 's3_version_race', 'audit_fail_closed', 'retention_exact_version', 'action_bindings', 'error_retryability', 'run_export_observability', 'static_analysis'];
    }

    private function sha256(string $relative): string { return hash('sha256', $this->read($this->root().'/'.$relative)); }
    private function root(): string { $root = realpath($this->repositoryRoot ?? getcwd()); if (!is_string($root)) throw new RuntimeException('plan_one_c_platform_evidence_invalid'); return str_replace('\\', '/', $root); }
    private function read(string $path): string { if (is_link($path) || !is_file($path)) throw new RuntimeException('plan_one_c_platform_evidence_invalid'); $bytes = file_get_contents($path); if (!is_string($bytes)) throw new RuntimeException('plan_one_c_platform_evidence_invalid'); return $bytes; }
    /** @return array<string, mixed> */
    private function decode(string $bytes): array { try { $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new RuntimeException('plan_one_c_platform_evidence_invalid'); } if (!is_array($data) || array_is_list($data)) throw new RuntimeException('plan_one_c_platform_evidence_invalid'); return $data; }
}
