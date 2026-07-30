<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Evidence;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportingArtifactTransfer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class ReportingArtifactTransferService
{
    private const TRANSFER_SCHEMA = 'docs/reports/contracts/reporting-artifact-transfer.schema.json';

    /**
     * @return array{kind:string,artifact_id:string,status:string,source:string,schema:string,destination:string,destination_schema:string,descriptor:string}
     */
    private function definition(string $kind): array
    {
        return match ($kind) {
            'activation' => [
                'kind' => $kind,
                'artifact_id' => 'report_catalog_activation_transfer',
                'status' => 'catalog_activated',
                'source' => 'build/reports/report-catalog-activation.json',
                'schema' => 'docs/reports/contracts/report-catalog-activation.schema.json',
                'destination' => 'build/reports/intake/report-catalog-activation.json',
                'destination_schema' => 'build/reports/intake/contracts/report-catalog-activation.schema.json',
                'descriptor' => 'build/reports/intake/report-catalog-activation.transfer.json',
            ],
            'admin-evidence' => [
                'kind' => $kind,
                'artifact_id' => 'plan4_admin_evidence_transfer',
                'status' => 'admin_evidence_passed',
                'source' => 'docs/reports/admin-evidence.json',
                'schema' => 'docs/reports/contracts/report-admin-evidence.schema.json',
                'destination' => 'build/reports/intake/plan-4-admin-evidence.json',
                'destination_schema' => 'build/reports/intake/contracts/report-admin-evidence.schema.json',
                'descriptor' => 'build/reports/intake/plan-4-admin-evidence.transfer.json',
            ],
            'release' => [
                'kind' => $kind,
                'artifact_id' => 'report_release_evidence_transfer',
                'status' => 'release_passed',
                'source' => 'build/reports/report-release-evidence.json',
                'schema' => 'docs/reports/contracts/report-quality-evidence.schema.json',
                'destination' => 'build/reports/intake/report-release-evidence.json',
                'destination_schema' => 'build/reports/intake/contracts/report-quality-evidence.schema.json',
                'descriptor' => 'build/reports/intake/report-release-evidence.transfer.json',
            ],
            default => throw new InvalidArgumentException('reporting_artifact_transfer_invalid'),
        };
    }

    public function transfer(
        string $kind,
        string $sourceRoot,
        string $sourcePath,
        string $schemaPath,
        string $sourceCommitSha,
        string $releaseSha,
        string $activationCommitSha,
        ?ReportingArtifactTransfer $adminTransfer,
        string $destinationRoot,
        DateTimeImmutable $generatedAt,
        bool $check,
    ): ReportingArtifactTransfer {
        $definition = $this->definition($kind);
        if ($sourcePath !== $definition['source'] || $schemaPath !== $definition['schema']) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
        if (($kind === 'release') !== ($adminTransfer !== null)) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
        if ($adminTransfer !== null && $adminTransfer->artifactId !== 'plan4_admin_evidence_transfer') {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }

        $sourceRoot = $this->root($sourceRoot);
        $destinationRoot = $this->root($destinationRoot);
        if ($sourceRoot === $destinationRoot) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
        $this->assertTimestamp($generatedAt);

        $artifact = $this->read($sourceRoot, $definition['source']);
        $schema = $this->read($sourceRoot, $definition['schema']);
        $transferSchema = $this->read($sourceRoot, self::TRANSFER_SCHEMA);
        $document = json_decode($artifact, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($document) || ($document['status'] ?? null) !== $definition['status']) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }

        $transfer = new ReportingArtifactTransfer(
            $definition['artifact_id'],
            $kind,
            'artifact_transferred',
            $definition['source'],
            $definition['destination'],
            $definition['schema'],
            $releaseSha,
            $sourceCommitSha,
            $activationCommitSha,
            $adminTransfer?->sourceCommitSha,
            $generatedAt,
            hash('sha256', $artifact),
            hash('sha256', $artifact),
            hash('sha256', $schema),
            hash('sha256', $transferSchema),
        );

        if ($check) {
            return $transfer;
        }

        $this->publish($destinationRoot, $definition, $artifact, $schema, $transferSchema, $transfer);

        return $transfer;
    }

    private function publish(string $root, array $definition, string $artifact, string $schema, string $transferSchema, ReportingArtifactTransfer $transfer): void
    {
        $files = [
            $definition['destination'] => $artifact,
            $definition['destination_schema'] => $schema,
            'build/reports/intake/contracts/reporting-artifact-transfer.schema.json' => $transferSchema,
        ];
        foreach ($files as $path => $bytes) {
            $this->atomicWrite($root, $path, $bytes);
            if ($this->read($root, $path) !== $bytes) {
                throw new RuntimeException('reporting_artifact_transfer_final_mismatch');
            }
        }
        $descriptor = [
            'artifact_id' => $transfer->artifactId,
            'schema_version' => $transfer->schemaVersion,
            'kind' => $transfer->kind,
            'status' => $transfer->status,
            'source_path' => $transfer->sourcePath,
            'destination_path' => $transfer->destinationPath,
            'schema_path' => $transfer->schemaPath,
            'source_sha256' => $transfer->sourceSha256,
            'destination_sha256' => $transfer->destinationSha256,
            'schema_sha256' => $transfer->schemaSha256,
            'transfer_schema_sha256' => $transfer->transferSchemaSha256,
            'release_sha' => $transfer->releaseSha,
            'source_commit_sha' => $transfer->sourceCommitSha,
            'activation_commit_sha' => $transfer->activationCommitSha,
            'generated_at' => $transfer->generatedAt->format('Y-m-d\\TH:i:s\\Z'),
        ];
        if ($transfer->adminEvidenceCommitSha !== null) {
            $descriptor['admin_evidence_commit_sha'] = $transfer->adminEvidenceCommitSha;
        }
        $descriptor = CanonicalJson::encode($descriptor)."\n";
        $this->atomicWrite($root, $definition['descriptor'], $descriptor);
        if ($this->read($root, $definition['descriptor']) !== $descriptor) {
            throw new RuntimeException('reporting_artifact_transfer_final_mismatch');
        }
    }

    private function assertTimestamp(DateTimeImmutable $generatedAt): void
    {
        if ($generatedAt->format('Y-m-d\\TH:i:s\\Z') !== $generatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z')
            || $generatedAt > new DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
    }

    private function root(string $root): string
    {
        $resolved = realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }

        return str_replace('\\', '/', $resolved);
    }

    private function read(string $root, string $relativePath): string
    {
        $path = $root.'/'.$relativePath;
        if (is_link($path) || ! is_file($path) || ! str_starts_with(str_replace('\\', '/', (string) realpath($path)), $root.'/')) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new InvalidArgumentException('reporting_artifact_transfer_invalid');
        }

        return $bytes;
    }

    private function atomicWrite(string $root, string $relativePath, string $bytes): void
    {
        $path = $root.'/'.$relativePath;
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('reporting_artifact_transfer_write_failed');
        }
        $temporary = $directory.'/.reporting-transfer-'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $bytes) === false || ! rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('reporting_artifact_transfer_write_failed');
        }
    }
}
