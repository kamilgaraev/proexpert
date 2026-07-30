<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationLock;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class FilesystemReportPublicationLedger
{
    private object $schema;

    private Closure $atomicRename;

    private Closure $ledgerWriter;

    public function __construct(
        private Draft202012SchemaValidator $validator,
        string $schemaPath,
        ?Closure $atomicRename = null,
        ?Closure $ledgerWriter = null,
    ) {
        $bytes = @file_get_contents($schemaPath);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_publication_ledger_schema_unreadable');
        }
        try {
            $schema = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_publication_ledger_schema_invalid', 0, $exception);
        }
        if (! is_object($schema)) {
            throw new RuntimeException('report_publication_ledger_schema_invalid');
        }
        $this->schema = $schema;
        $this->atomicRename = $atomicRename
            ?? static fn (string $temporary, string $final): bool => rename($temporary, $final);
        $this->ledgerWriter = $ledgerWriter
            ?? static function (string $path, string $replacement): bool {
                $handle = fopen($path, 'c+b');
                if (! is_resource($handle)) {
                    return false;
                }
                try {
                    if (! flock($handle, LOCK_EX)
                        || ! ftruncate($handle, 0)
                        || rewind($handle) === false) {
                        return false;
                    }
                    $written = fwrite($handle, $replacement);
                    if ($written !== strlen($replacement) || ! fflush($handle)) {
                        return false;
                    }
                    if (function_exists('fsync') && ! fsync($handle)) {
                        return false;
                    }

                    return true;
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            };
    }

    public function append(string $path, ReportPublicationLock $lock): void
    {
        $this->publish($path, $lock, [], static function (): void {});
    }

    public function publish(
        string $ledgerPath,
        ReportPublicationLock $lock,
        array $artifacts,
        Closure $verifyArtifact,
    ): void {
        $this->assertLedgerPath($ledgerPath);
        $lockPath = $ledgerPath.'.lock';
        if (is_link($lockPath) || (file_exists($lockPath) && ! is_file($lockPath))) {
            throw new RuntimeException('report_publication_ledger_lock_invalid');
        }
        $lockHandle = fopen($lockPath, 'c+b');
        if (! is_resource($lockHandle) || ! flock($lockHandle, LOCK_EX)) {
            if (is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            throw new RuntimeException('report_publication_ledger_lock_failed');
        }

        try {
            $this->publishUnderLock(
                $ledgerPath,
                $lock,
                $this->normalizeArtifacts($ledgerPath, $artifacts),
                $verifyArtifact,
            );
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function publishUnderLock(
        string $ledgerPath,
        ReportPublicationLock $lock,
        array $artifacts,
        Closure $verifyArtifact,
    ): void {
        $originalLedger = file_exists($ledgerPath) ? $this->read($ledgerPath) : null;
        [$ledgerBytes, $changed] = $this->nextBytes($originalLedger, $lock);
        if (! $changed && $artifacts === []) {
            return;
        }

        $temporaryArtifacts = [];
        $publishedArtifacts = [];
        $ledgerTemporary = null;
        try {
            foreach ($artifacts as $path => $bytes) {
                $temporary = $this->stage($path, $bytes);
                $verifyArtifact($path, $temporary, $bytes);
                $temporaryArtifacts[$path] = $temporary;
            }
            $ledgerTemporary = $this->stage($ledgerPath, $ledgerBytes);
            $this->validatedDocument($this->read($ledgerTemporary), $ledgerBytes);

            foreach ($temporaryArtifacts as $path => $temporary) {
                if (! ($this->atomicRename)($temporary, $path)) {
                    throw new RuntimeException('report_publication_artifact_rename_failed');
                }
                $publishedArtifacts[] = $path;
                unset($temporaryArtifacts[$path]);
            }

            if ($changed) {
                $this->replaceLedger($ledgerPath, $ledgerBytes, $originalLedger);
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($publishedArtifacts) as $path) {
                if (is_file($path) && ! unlink($path)) {
                    throw new RuntimeException('report_publication_rollback_failed', 0, $exception);
                }
            }
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('report_publication_transaction_failed', 0, $exception);
        } finally {
            foreach ($temporaryArtifacts as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
            if (is_string($ledgerTemporary) && is_file($ledgerTemporary)) {
                unlink($ledgerTemporary);
            }
        }
    }

    private function replaceLedger(
        string $path,
        string $replacement,
        ?string $original,
    ): void {
        if (! ($this->ledgerWriter)($path, $replacement)) {
            $this->restoreLedger($path, $original);
            throw new RuntimeException('report_publication_ledger_write_failed');
        }

        try {
            $reread = $this->read($path);
            $this->validatedDocument($reread, $replacement);
        } catch (Throwable $exception) {
            $this->restoreLedger($path, $original);
            throw new RuntimeException('report_publication_ledger_reread_failed', 0, $exception);
        }
    }

    private function restoreLedger(string $path, ?string $original): void
    {
        if ($original === null) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('report_publication_ledger_rollback_failed');
            }

            return;
        }
        if (! ($this->ledgerWriter)($path, $original)
            || ! hash_equals($original, $this->read($path))) {
            throw new RuntimeException('report_publication_ledger_rollback_failed');
        }
    }

    private function nextBytes(?string $currentBytes, ReportPublicationLock $lock): array
    {
        $document = $currentBytes === null
            ? [
                'artifact_id' => 'report_publication_ledger',
                'events' => [],
                'schema_version' => '1.0.0',
            ]
            : $this->validatedDocument($currentBytes);
        $event = $this->event($lock);
        foreach ($document['events'] as $existing) {
            if (($existing['event_id'] ?? null) !== $event['event_id']) {
                continue;
            }
            if (CanonicalJson::encode($existing) === CanonicalJson::encode($event)) {
                return [$currentBytes, false];
            }
            throw new RuntimeException('report_publication_ledger_event_conflict');
        }
        $document['events'][] = $event;
        $bytes = CanonicalJson::encode($document)."\n";
        $this->validatedDocument($bytes, $bytes);

        return [$bytes, true];
    }

    private function normalizeArtifacts(string $ledgerPath, array $artifacts): array
    {
        if ($artifacts !== [] && array_is_list($artifacts)) {
            throw new RuntimeException('report_publication_artifacts_invalid');
        }
        $normalized = [];
        foreach ($artifacts as $path => $bytes) {
            if (! is_string($path)
                || ! is_string($bytes)
                || $bytes === ''
                || hash_equals($path, $ledgerPath)
                || isset($normalized[$path])
                || file_exists($path)
                || is_link($path)
                || ! is_dir(dirname($path))
                || is_link(dirname($path))) {
                throw new RuntimeException('report_publication_artifacts_invalid');
            }
            $normalized[$path] = $bytes;
        }

        return $normalized;
    }

    private function stage(string $path, string $bytes): string
    {
        $temporary = tempnam(dirname($path), '.report-publication-');
        if (! is_string($temporary)) {
            throw new RuntimeException('report_publication_stage_failed');
        }
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            unlink($temporary);
            throw new RuntimeException('report_publication_stage_failed');
        }
        $reread = $this->read($temporary);
        if (! hash_equals(hash('sha256', $bytes), hash('sha256', $reread))) {
            unlink($temporary);
            throw new RuntimeException('report_publication_stage_hash_invalid');
        }

        return $temporary;
    }

    private function event(ReportPublicationLock $lock): array
    {
        return [
            'event_id' => sprintf(
                'reports:definition:%s:published:%s',
                $lock->code,
                $lock->definitionHash->value,
            ),
            'event_type' => 'definition_published',
            'lock' => $lock->canonicalPayload(),
            'lock_digest' => $lock->digest()->value,
        ];
    }

    private function validatedDocument(string $bytes, ?string $expectedBytes = null): array
    {
        if ($expectedBytes !== null && ! hash_equals($expectedBytes, $bytes)) {
            throw new RuntimeException('report_publication_ledger_bytes_invalid');
        }
        try {
            $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_publication_ledger_json_invalid', 0, $exception);
        }
        if (! is_array($document)
            || array_is_list($document)
            || ! isset($document['events'])
            || ! is_array($document['events'])
            || ! hash_equals(CanonicalJson::encode($document)."\n", $bytes)) {
            throw new RuntimeException('report_publication_ledger_noncanonical');
        }
        $object = json_decode(
            json_encode($document, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (! is_object($object)) {
            throw new RuntimeException('report_publication_ledger_json_invalid');
        }
        $this->validator->assertValid(
            $object,
            $this->schema,
            'most.report-publication-ledger.v1',
        );

        return $document;
    }

    private function assertLedgerPath(string $path): void
    {
        if (is_link($path)
            || (file_exists($path) && ! is_file($path))
            || ! is_dir(dirname($path))
            || is_link(dirname($path))) {
            throw new RuntimeException('report_publication_ledger_path_invalid');
        }
    }

    private function read(string $path): string
    {
        $bytes = @file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_publication_read_failed');
        }

        return $bytes;
    }
}
