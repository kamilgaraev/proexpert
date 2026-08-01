<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseRequest
{
    private const ARTIFACT_PATH_KEYS = ['candidate_manifest', 'conformance_evidence', 'proof_template'];

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
        $expectedArtifactKeys = self::ARTIFACT_PATH_KEYS;
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
            || ! is_string($payload['code'])
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $payload['code']) !== 1
            || ! is_string($payload['commit_sha'])
            || preg_match('/^[a-f0-9]{40}$/D', $payload['commit_sha']) !== 1
            || ! is_string($payload['proof_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['proof_sha256']) !== 1
            || ! is_array($payload['artifact_paths'])
            || $artifactKeys !== $expectedArtifactKeys) {
            throw new InvalidArgumentException('report_publication_release_request_invalid');
        }

        foreach ($payload['artifact_paths'] as $artifactPath) {
            if (! is_string($artifactPath)
                || preg_match('/^[a-z][a-z0-9_-]{1,127}\\.json$/D', $artifactPath) !== 1) {
                throw new InvalidArgumentException('report_publication_release_request_invalid');
            }
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
