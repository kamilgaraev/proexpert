<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

final readonly class ReportPublicationReleaseArtifact
{
    private const ROOT_KEYS = [
        'algorithm',
        'artifact_id',
        'evidence',
        'issuer',
        'key_id',
        'provenance',
        'schema_version',
        'signature',
        'subject',
    ];

    private const PROVENANCE_KEYS = [
        'artifact_name',
        'commit_sha',
        'job',
        'repository',
        'run_attempt',
        'run_id',
        'workflow_ref',
    ];

    private const SUBJECT_KEYS = [
        'approver_identity',
        'binding_sha256',
        'candidate_definition_sha256',
        'candidate_manifest_sha256',
        'code',
        'conformance_evidence_sha256',
        'official_manifest_sha256',
        'proof_sha256',
        'release_created_at_utc',
        'release_git_sha',
    ];

    private const EVIDENCE_KEYS = [
        'checks',
        'commit_sha',
        'completed_at_utc',
        'run_id',
    ];

    private function __construct(private array $payload) {}

    public static function fromCanonicalBytes(string $bytes): self
    {
        try {
            $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('report_publication_release_artifact_invalid', 0, $exception);
        }
        if (! is_array($payload)
            || array_is_list($payload)
            || ! hash_equals(CanonicalJson::encode($payload), $bytes)) {
            self::invalid();
        }

        return self::fromArray($payload);
    }

    public static function fromArray(array $payload): self
    {
        self::assertMap($payload, self::ROOT_KEYS);
        if ($payload['artifact_id'] !== 'most.report_publication.release'
            || $payload['schema_version'] !== '1.0.0'
            || $payload['algorithm'] !== 'ed25519') {
            self::invalid();
        }
        self::assertPattern($payload['issuer'], '/^[a-z][a-z0-9_.-]{2,63}$/D');
        self::assertPattern($payload['key_id'], '/^[a-z0-9][a-z0-9_.:-]{2,63}$/D');
        self::assertPattern($payload['signature'], '/^[A-Za-z0-9_-]{86}$/D');
        self::assertProvenance($payload['provenance']);
        self::assertSubject($payload['subject']);
        self::assertEvidence($payload['evidence']);

        return new self($payload);
    }

    public function payload(): array
    {
        return $this->payload;
    }

    public function canonicalBytes(): string
    {
        return CanonicalJson::encode($this->payload);
    }

    public function signedPayloadBytes(): string
    {
        $unsigned = $this->payload;
        unset($unsigned['signature']);

        return CanonicalJson::encode($unsigned);
    }

    public function evidenceBytes(): string
    {
        return CanonicalJson::encode($this->payload['evidence']);
    }

    private static function assertProvenance(mixed $provenance): void
    {
        self::assertMap($provenance, self::PROVENANCE_KEYS);
        self::assertPattern($provenance['repository'], '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/D');
        self::assertPattern($provenance['workflow_ref'], '/^\.github\/workflows\/[A-Za-z0-9_.-]+\.ya?ml@refs\/heads\/[A-Za-z0-9._\/-]+$/D');
        self::assertPattern($provenance['job'], '/^[a-z][a-z0-9_-]{2,127}$/D');
        self::assertPattern($provenance['run_id'], '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
        self::assertGitSha($provenance['commit_sha']);
        self::assertPattern($provenance['artifact_name'], '/^report-publication-[a-z][a-z0-9_]{2,63}-[a-f0-9]{64}$/D');
        if (! is_int($provenance['run_attempt']) || $provenance['run_attempt'] < 1) {
            self::invalid();
        }
    }

    private static function assertSubject(mixed $subject): void
    {
        self::assertMap($subject, self::SUBJECT_KEYS);
        self::assertPattern($subject['code'], '/^[a-z][a-z0-9_]{2,63}$/D');
        foreach ([
            'binding_sha256',
            'candidate_definition_sha256',
            'candidate_manifest_sha256',
            'conformance_evidence_sha256',
            'official_manifest_sha256',
            'proof_sha256',
        ] as $field) {
            self::assertHash($subject[$field]);
        }
        self::assertGitSha($subject['release_git_sha']);
        self::assertTimestamp($subject['release_created_at_utc']);
        self::assertPattern($subject['approver_identity'], '/^[A-Za-z0-9][A-Za-z0-9@._:-]{2,127}$/D');
    }

    private static function assertEvidence(mixed $evidence): void
    {
        self::assertMap($evidence, self::EVIDENCE_KEYS);
        self::assertPattern($evidence['run_id'], '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D');
        self::assertGitSha($evidence['commit_sha']);
        self::assertTimestamp($evidence['completed_at_utc']);
        if (! is_array($evidence['checks']) || array_is_list($evidence['checks']) || $evidence['checks'] === []) {
            self::invalid();
        }
        $checks = array_keys($evidence['checks']);
        $sorted = $checks;
        sort($sorted, SORT_STRING);
        if ($checks !== $sorted) {
            self::invalid();
        }
        foreach ($evidence['checks'] as $check => $status) {
            self::assertPattern($check, '/^[a-z][a-z0-9_]{2,63}$/D');
            if ($status !== 'passed') {
                self::invalid();
            }
        }
    }

    private static function assertMap(mixed $value, array $keys): void
    {
        if (! is_array($value) || array_is_list($value)) {
            self::invalid();
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function assertHash(mixed $value): void
    {
        self::assertPattern($value, '/^[a-f0-9]{64}$/D');
    }

    private static function assertGitSha(mixed $value): void
    {
        self::assertPattern($value, '/^[a-f0-9]{40}$/D');
    }

    private static function assertTimestamp(mixed $value): void
    {
        self::assertPattern(
            $value,
            '/^(?:19|20)[0-9]{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{6}Z$/D',
        );
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value);
        if (! $parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d\TH:i:s.u\Z') !== $value) {
            self::invalid();
        }
    }

    private static function assertPattern(mixed $value, string $pattern): void
    {
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            self::invalid();
        }
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('report_publication_release_artifact_invalid');
    }
}
