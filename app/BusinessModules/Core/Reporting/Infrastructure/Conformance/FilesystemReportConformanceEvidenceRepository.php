<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Conformance;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportConformanceEvidenceRepository;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class FilesystemReportConformanceEvidenceRepository implements ReportConformanceEvidenceRepository
{
    private Closure $atomicRename;

    private string $repositoryRoot;

    private object $schema;

    public function __construct(
        string $repositoryRoot,
        private Draft202012SchemaValidator $validator,
        ?Closure $atomicRename = null,
        ?string $schemaPath = null,
    ) {
        if (@lstat($repositoryRoot) === false || is_link($repositoryRoot)) {
            throw new RuntimeException('report_conformance_repository_root_invalid');
        }
        $resolvedRoot = realpath($repositoryRoot);
        if (! is_string($resolvedRoot) || ! is_dir($resolvedRoot)) {
            throw new RuntimeException('report_conformance_repository_root_invalid');
        }
        $this->repositoryRoot = $this->normalizePath($resolvedRoot);
        $this->atomicRename = $atomicRename
            ?? static fn (string $temporary, string $final): bool => rename($temporary, $final);

        $schemaCandidate = $schemaPath
            ?? $this->repositoryRoot.'/docs/reports/contracts/report-conformance-evidence.schema.json';
        $this->assertNoSymlinksWithinRoot(
            $schemaCandidate,
            true,
            'report_conformance_schema_path_invalid',
        );
        $resolvedSchema = realpath($schemaCandidate);
        if (! is_string($resolvedSchema)
            || ! is_file($resolvedSchema)
            || ! str_starts_with(
                $this->normalizePath($resolvedSchema),
                $this->repositoryRoot.'/docs/reports/contracts/',
            )) {
            throw new RuntimeException('report_conformance_schema_path_invalid');
        }
        $this->schema = $this->decodeObject($this->read($resolvedSchema));
    }

    public function get(
        string $code,
        Sha256Hash $definitionHash,
        Sha256Hash $fixtureHash,
    ): ReportDefinitionConformanceEvidence {
        $relativePath = $this->relativePath($code, $definitionHash, $fixtureHash);
        $candidatePath = $this->repositoryRoot.'/'.$relativePath;
        $this->assertNoSymlinksWithinRoot(
            $candidatePath,
            true,
            'report_conformance_evidence_not_found',
        );
        $path = realpath($candidatePath);
        if (! is_string($path)
            || ! is_file($path)
            || ! str_starts_with(
                $this->normalizePath($path),
                $this->repositoryRoot.'/build/reports/conformance/',
            )) {
            throw new RuntimeException('report_conformance_evidence_not_found');
        }

        $document = $this->validatedDocument($this->read($path));
        $evidence = $this->hydrate($document);
        if (! hash_equals($code, $evidence->code)
            || ! hash_equals($definitionHash->value, $evidence->definitionHash->value)
            || ! hash_equals($fixtureHash->value, $evidence->fixtureHash->value)) {
            throw new RuntimeException('report_conformance_evidence_identity_invalid');
        }

        return $evidence;
    }

    public function put(ReportDefinitionConformanceEvidence $evidence): void
    {
        $document = $this->document($evidence);
        $this->assertValid($document);
        $bytes = CanonicalJson::encode($document)."\n";
        $relativePath = $this->relativePath(
            $evidence->code,
            $evidence->definitionHash,
            $evidence->fixtureHash,
        );
        $path = $this->repositoryRoot.'/'.$relativePath;
        $directory = dirname($path);
        $this->assertNoSymlinksWithinRoot(
            $directory,
            false,
            'report_conformance_evidence_path_invalid',
        );
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('report_conformance_evidence_directory_failed');
        }
        $this->assertNoSymlinksWithinRoot(
            $directory,
            true,
            'report_conformance_evidence_path_invalid',
        );
        $this->assertNoSymlinksWithinRoot(
            $path,
            false,
            'report_conformance_evidence_path_invalid',
        );
        $resolvedDirectory = realpath($directory);
        if (! is_string($resolvedDirectory)
            || ! str_starts_with(
                $this->normalizePath($resolvedDirectory).'/',
                $this->repositoryRoot.'/build/reports/conformance/',
            )
            || is_link($path)
            || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('report_conformance_evidence_path_invalid');
        }

        $temporary = tempnam($directory, '.conformance-');
        if (! is_string($temporary)) {
            throw new RuntimeException('report_conformance_evidence_write_failed');
        }

        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('report_conformance_evidence_write_failed');
            }
            $this->validatedDocument($this->read($temporary), $bytes);
            if (! ($this->atomicRename)($temporary, $path)) {
                throw new RuntimeException('report_conformance_evidence_rename_failed');
            }
            try {
                $reread = $this->validatedDocument($this->read($path), $bytes);
                $hydrated = $this->hydrate($reread);
                if (! hash_equals($evidence->digest()->value, $hydrated->digest()->value)) {
                    throw new RuntimeException('report_conformance_evidence_digest_invalid');
                }
            } catch (Throwable $exception) {
                if (is_file($path)) {
                    unlink($path);
                }
                throw new RuntimeException(
                    'report_conformance_evidence_reread_failed',
                    0,
                    $exception,
                );
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function relativePath(
        string $code,
        Sha256Hash $definitionHash,
        Sha256Hash $fixtureHash,
    ): string {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1) {
            throw new RuntimeException('report_conformance_evidence_identity_invalid');
        }

        return 'build/reports/conformance/'.$code.'/'.$definitionHash->value.'/'.$fixtureHash->value.'.json';
    }

    private function document(ReportDefinitionConformanceEvidence $evidence): array
    {
        return [
            ...$evidence->canonicalPayload(),
            'digest' => $evidence->digest()->value,
        ];
    }

    private function validatedDocument(string $bytes, ?string $expectedBytes = null): array
    {
        if ($expectedBytes !== null && ! hash_equals($expectedBytes, $bytes)) {
            throw new RuntimeException('report_conformance_evidence_bytes_invalid');
        }
        $document = $this->decodeArray($bytes);
        if (! hash_equals(CanonicalJson::encode($document)."\n", $bytes)) {
            throw new RuntimeException('report_conformance_evidence_noncanonical');
        }
        $this->assertValid($document);
        $evidence = $this->hydrate($document);
        if (! hash_equals($document['digest'], $evidence->digest()->value)) {
            throw new RuntimeException('report_conformance_evidence_digest_invalid');
        }

        return $document;
    }

    private function assertValid(array $document): void
    {
        $this->validator->assertValid(
            $this->toObject($document),
            $this->schema,
            'most.report-conformance-evidence.v1',
        );
    }

    private function hydrate(array $document): ReportDefinitionConformanceEvidence
    {
        $componentHashes = [];
        foreach ($document['component_class_hashes'] as $component) {
            $componentHashes[$component['class']] = new Sha256Hash($component['sha256']);
        }
        $source = $document['source'];
        $formula = $document['formula'];

        return new ReportDefinitionConformanceEvidence(
            $document['code'],
            new Sha256Hash($document['definition_hash']),
            $document['contract_version'],
            $document['source_schema_version'],
            new Sha256Hash($document['fixture_hash']),
            new ReportSourceConformanceEvidence(
                new Sha256Hash($source['source_hash']),
                $source['snapshot_kind'],
                $source['snapshot_id'],
                $source['row_count'],
                new Sha256Hash($source['rows_hash']),
                $source['passed'],
                $source['assertion_codes'],
            ),
            new ReportFormulaConformanceEvidence(
                $formula['formula_version'],
                new Sha256Hash($formula['totals_hash']),
                $formula['passed'],
                $formula['assertion_codes'],
            ),
            $componentHashes,
            $document['assertion_count'],
            $document['status'],
            $document['commit_sha'],
            new DateTimeImmutable($document['generated_at']),
        );
    }

    private function decodeArray(string $bytes): array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_conformance_evidence_json_invalid', 0, $exception);
        }
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('report_conformance_evidence_json_invalid');
        }

        return $decoded;
    }

    private function decodeObject(string $bytes): object
    {
        try {
            $decoded = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_conformance_schema_json_invalid', 0, $exception);
        }
        if (! is_object($decoded)) {
            throw new RuntimeException('report_conformance_schema_json_invalid');
        }

        return $decoded;
    }

    private function toObject(array $document): object
    {
        return $this->decodeObject(json_encode($document, JSON_THROW_ON_ERROR));
    }

    private function read(string $path): string
    {
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_conformance_evidence_read_failed');
        }

        return $bytes;
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private function assertNoSymlinksWithinRoot(
        string $path,
        bool $mustExist,
        string $error,
    ): void {
        $current = $this->normalizePath($path);
        $root = $this->repositoryRoot;
        $first = true;

        while (true) {
            $stat = @lstat($current);
            if ($stat === false) {
                if ($first && $mustExist) {
                    throw new RuntimeException($error);
                }
            } elseif (is_link($current)) {
                throw new RuntimeException($error);
            }

            if ($current === $root) {
                return;
            }
            if (! str_starts_with($current.'/', $root.'/')) {
                throw new RuntimeException($error);
            }

            $parent = $this->normalizePath(dirname($current));
            if ($parent === $current) {
                throw new RuntimeException($error);
            }
            $current = $parent;
            $first = false;
        }
    }
}
