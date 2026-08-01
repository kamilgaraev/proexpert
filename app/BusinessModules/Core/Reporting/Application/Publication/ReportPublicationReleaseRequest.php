<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseRequest
{
    private const ARTIFACT_PATHS = [
        'candidate_manifest' => 'r15-candidate-manifest.json',
        'conformance_evidence' => 'r15-conformance-evidence.json',
        'proof_template' => 'r15-proof-template.json',
    ];

    private function __construct(
        public string $requestId,
        public string $schemaVersion,
        public string $code,
        public string $commitSha,
        public string $proofSha256,
        public array $artifactPaths,
    ) {}

    public static function fromArray(array $payload): self
    {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $artifactKeys = is_array($payload['artifact_paths'] ?? null)
            ? array_keys($payload['artifact_paths'])
            : [];
        sort($artifactKeys, SORT_STRING);
        $expectedArtifactKeys = array_keys(self::ARTIFACT_PATHS);
        sort($expectedArtifactKeys, SORT_STRING);

        if ($keys !== [
                'artifact_paths',
                'code',
                'commit_sha',
                'proof_sha256',
                'request_id',
                'schema_version',
            ]
            || $payload['schema_version'] !== '1.0.0'
            || ! is_string($payload['request_id'])
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $payload['request_id']) !== 1
            || $payload['code'] !== 'procurement_cycle'
            || ! is_string($payload['commit_sha'])
            || preg_match('/^[a-f0-9]{40}$/D', $payload['commit_sha']) !== 1
            || ! is_string($payload['proof_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['proof_sha256']) !== 1
            || ! is_array($payload['artifact_paths'])
            || $artifactKeys !== $expectedArtifactKeys
            || $payload['artifact_paths'] !== self::ARTIFACT_PATHS) {
            throw new InvalidArgumentException('report_publication_release_request_invalid');
        }

        return new self(
            $payload['request_id'],
            $payload['schema_version'],
            $payload['code'],
            $payload['commit_sha'],
            $payload['proof_sha256'],
            $payload['artifact_paths'],
        );
    }
}
