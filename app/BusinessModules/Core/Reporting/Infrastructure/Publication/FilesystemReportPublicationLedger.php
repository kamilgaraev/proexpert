<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationLock;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Closure;
use DateTimeImmutable;
use JsonException;
use RuntimeException;
use Throwable;

final readonly class FilesystemReportPublicationLedger
{
    private object $schema;

    private Closure $atomicRename;

    private Closure $afterLedgerBackup;

    public function __construct(
        private Draft202012SchemaValidator $validator,
        string $schemaPath,
        ?Closure $atomicRename = null,
        ?Closure $afterLedgerBackup = null,
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
        $this->afterLedgerBackup = $afterLedgerBackup
            ?? static function (): void {};
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
        $this->recoverUnderLock($ledgerPath);
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
                $this->replaceLedger(
                    $ledgerPath,
                    $ledgerTemporary,
                    $ledgerBytes,
                    $originalLedger,
                );
                $ledgerTemporary = null;
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
        string $temporary,
        string $replacement,
        ?string $original,
    ): void {
        if ($original === null) {
            if (! ($this->atomicRename)($temporary, $path)) {
                throw new RuntimeException('report_publication_ledger_replace_failed');
            }
            try {
                $this->validatedDocument($this->read($path), $replacement);
            } catch (Throwable $exception) {
                if (is_file($path)) {
                    unlink($path);
                }
                throw new RuntimeException('report_publication_ledger_reread_failed', 0, $exception);
            }

            return;
        }

        $backup = $path.'.backup';
        $journal = $path.'.journal';
        if (file_exists($backup) || is_link($backup) || file_exists($journal) || is_link($journal)) {
            throw new RuntimeException('report_publication_ledger_recovery_state_invalid');
        }
        $this->writeJournal($journal, [
            'artifact_id' => 'report_publication_ledger_transaction',
            'new_hash' => hash('sha256', $replacement),
            'old_hash' => hash('sha256', $original),
            'stage_name' => basename($temporary),
            'state' => 'pending',
        ]);
        if (! ($this->atomicRename)($path, $backup)) {
            unlink($journal);
            throw new RuntimeException('report_publication_ledger_backup_failed');
        }
        ($this->afterLedgerBackup)($path, $backup, $temporary, $journal);

        try {
            if (! ($this->atomicRename)($temporary, $path)) {
                throw new RuntimeException('report_publication_ledger_replace_failed');
            }
            $this->validatedDocument($this->read($path), $replacement);
            if (! unlink($backup)) {
                throw new RuntimeException('report_publication_ledger_backup_cleanup_failed');
            }
            if (! unlink($journal)) {
                throw new RuntimeException('report_publication_ledger_journal_cleanup_failed');
            }
        } catch (Throwable $exception) {
            if (! is_file($backup)
                && is_file($path)
                && hash_equals(hash('sha256', $replacement), hash('sha256', $this->read($path)))) {
                throw $exception instanceof RuntimeException
                    ? $exception
                    : new RuntimeException('report_publication_ledger_replace_failed', 0, $exception);
            }
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('report_publication_ledger_rollback_failed', 0, $exception);
            }
            if (! is_file($backup)
                || ! ($this->atomicRename)($backup, $path)
                || ! hash_equals($original, $this->read($path))) {
                throw new RuntimeException('report_publication_ledger_rollback_failed', 0, $exception);
            }
            if (is_file($journal)) {
                unlink($journal);
            }
            throw $exception instanceof RuntimeException
                ? $exception
                : new RuntimeException('report_publication_ledger_replace_failed', 0, $exception);
        }
    }

    private function recoverUnderLock(string $path): void
    {
        $journalPath = $path.'.journal';
        $backupPath = $path.'.backup';
        if (! file_exists($journalPath)) {
            if (file_exists($backupPath) || is_link($backupPath)) {
                throw new RuntimeException('report_publication_ledger_orphan_backup');
            }

            return;
        }
        if (is_link($journalPath) || ! is_file($journalPath)
            || is_link($backupPath)
            || (file_exists($backupPath) && ! is_file($backupPath))) {
            throw new RuntimeException('report_publication_ledger_recovery_state_invalid');
        }

        $journal = $this->readJournal($journalPath);
        $stagedPath = dirname($path).DIRECTORY_SEPARATOR.$journal['stage_name'];
        if (dirname($stagedPath) !== dirname($path)
            || preg_match('/^(?:\\.report-publication-|\\.re)[A-Za-z0-9._-]+$/D', $journal['stage_name']) !== 1) {
            throw new RuntimeException('report_publication_ledger_recovery_state_invalid');
        }

        if (! file_exists($path)) {
            if (! is_file($backupPath)
                || ! hash_equals($journal['old_hash'], hash('sha256', $this->read($backupPath)))) {
                throw new RuntimeException('report_publication_ledger_recovery_failed');
            }
            $this->validatedDocument($this->read($backupPath));
            if (! ($this->atomicRename)($backupPath, $path)) {
                throw new RuntimeException('report_publication_ledger_recovery_failed');
            }
            $this->validatedDocument($this->read($path));
        } else {
            $finalBytes = $this->read($path);
            $finalHash = hash('sha256', $finalBytes);
            if (! hash_equals($journal['new_hash'], $finalHash)
                && ! hash_equals($journal['old_hash'], $finalHash)) {
                throw new RuntimeException('report_publication_ledger_recovery_failed');
            }
            $this->validatedDocument($finalBytes);
            if (file_exists($backupPath)) {
                $backupBytes = $this->read($backupPath);
                if (! hash_equals($journal['old_hash'], hash('sha256', $backupBytes))) {
                    throw new RuntimeException('report_publication_ledger_recovery_failed');
                }
                $this->validatedDocument($backupBytes);
                if (! unlink($backupPath)) {
                    throw new RuntimeException('report_publication_ledger_recovery_failed');
                }
            }
        }

        if (file_exists($stagedPath) && ! unlink($stagedPath)) {
            throw new RuntimeException('report_publication_ledger_recovery_failed');
        }
        if (! unlink($journalPath)) {
            throw new RuntimeException('report_publication_ledger_recovery_failed');
        }
    }

    private function writeJournal(string $path, array $journal): void
    {
        $bytes = CanonicalJson::encode($journal)."\n";
        $temporary = $this->stage($path, $bytes);
        if (! ($this->atomicRename)($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('report_publication_ledger_journal_write_failed');
        }
        if (! hash_equals($bytes, $this->read($path))) {
            throw new RuntimeException('report_publication_ledger_journal_write_failed');
        }
    }

    private function readJournal(string $path): array
    {
        $bytes = $this->read($path);
        try {
            $journal = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('report_publication_ledger_journal_invalid', 0, $exception);
        }
        if (! is_array($journal)
            || array_keys($journal) !== ['artifact_id', 'new_hash', 'old_hash', 'stage_name', 'state']
            || $journal['artifact_id'] !== 'report_publication_ledger_transaction'
            || $journal['state'] !== 'pending'
            || ! is_string($journal['new_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $journal['new_hash']) !== 1
            || ! is_string($journal['old_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $journal['old_hash']) !== 1
            || ! is_string($journal['stage_name'])
            || CanonicalJson::encode($journal)."\n" !== $bytes) {
            throw new RuntimeException('report_publication_ledger_journal_invalid');
        }

        return $journal;
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
        $this->assertEventSemantics($document['events']);

        return $document;
    }

    private function assertEventSemantics(array $events): void
    {
        $seen = [];
        foreach ($events as $event) {
            $lockPayload = is_array($event) ? ($event['lock'] ?? null) : null;
            if (! is_array($lockPayload) || array_is_list($lockPayload)) {
                throw new RuntimeException('report_publication_ledger_event_invalid');
            }
            $lock = new ReportPublicationLock(
                $lockPayload['code'],
                new Sha256Hash($lockPayload['previous_manifest_hash']),
                new Sha256Hash($lockPayload['candidate_manifest_hash']),
                new Sha256Hash($lockPayload['published_manifest_hash']),
                new Sha256Hash($lockPayload['definition_hash']),
                new Sha256Hash($lockPayload['conformance_hash']),
                $lockPayload['release_sha'],
                new DateTimeImmutable($lockPayload['published_at']),
            );
            $eventId = $event['event_id'];
            $expectedEventId = sprintf(
                'reports:definition:%s:published:%s',
                $lock->code,
                $lock->definitionHash->value,
            );
            if (isset($seen[$eventId])
                || ! hash_equals($eventId, $expectedEventId)
                || ! hash_equals($event['lock_digest'], $lock->digest()->value)
                || CanonicalJson::encode($lockPayload)
                    !== CanonicalJson::encode($lock->canonicalPayload())) {
                throw new RuntimeException('report_publication_ledger_event_invalid');
            }
            $seen[$eventId] = true;
        }
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
