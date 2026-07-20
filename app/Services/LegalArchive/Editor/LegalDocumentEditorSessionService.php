<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentEditorSession;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAbility;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentVersionAttempt;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Throwable;

final class LegalDocumentEditorSessionService
{
    public function __construct(
        private readonly LegalDocumentEditor $editor,
        private readonly EditorDocumentFetcher $fetcher,
        private readonly LegalDocumentFileService $files,
        private readonly LegalDocumentDownloadService $downloads,
        private readonly LegalDocumentAuthorizer $authorizer,
        private readonly LegalDocumentAudit $audit,
        private readonly ConnectionInterface $connection,
        private readonly LegalDocumentAggregateLock $aggregateLock = new LegalDocumentAggregateLock,
    ) {}

    public function open(LegalArchiveDocumentVersion $version, User $actor): EditorSessionPayload
    {
        $document = $this->documentForVersion($version);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::VIEW->value);
        $callbackBaseUrl = '';
        if ($this->editor->enabled()) {
            $callbackBaseUrl = rtrim((string) config('legal-document-editor.callback_base_url'), '/');
            if (! str_starts_with($callbackBaseUrl, 'https://')) {
                throw new DomainException('legal_document_editor_configuration_invalid');
            }
        }
        $viewerUrl = $this->downloads->temporaryUrl($version, $actor, 'preview');
        $sourceUrlMinutes = max(1, (int) config('file-uploads.legal_archive.temporary_url_minutes', 5));
        $sessionMinutes = min(
            max(1, (int) config('legal-document-editor.session_ttl_minutes', 4)),
            max(1, $sourceUrlMinutes - 1),
        );
        $expiresAt = new DateTimeImmutable("+{$sessionMinutes} minutes");
        if (! $this->editor->enabled()) {
            return new EditorSessionPayload(false, 'viewer', '', '', null, null, [], $expiresAt, $viewerUrl, 'not_configured');
        }

        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::EDIT->value);
        $session = $this->connection->transaction(function () use ($version, $document, $actor, $expiresAt): LegalDocumentEditorSession {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $version->id);
            $this->assertEditable($lockedDocument, $lockedVersion);
            $this->authorizer->authorize($actor, $lockedDocument, LegalDocumentAbility::EDIT->value);
            $existing = $this->sessions()->where('organization_id', $lockedDocument->organization_id)
                ->where('document_id', $lockedDocument->id)->where('source_version_id', $lockedVersion->id)
                ->whereIn('status', ['active', 'processing'])->lockForUpdate()->first();
            if ($existing instanceof LegalDocumentEditorSession) {
                if ($existing->expires_at?->isPast()) {
                    LegalDocumentEditorSession::serviceMutation(function () use ($existing): void {
                        $existing->forceFill(['status' => 'expired', 'completed_at' => now()])->save();
                    });
                    $this->audit->recordForActorId('editor_session_expired', $lockedDocument, (int) $existing->opened_by_user_id, [
                        'source_event_id' => 'editor-session:'.$existing->id.':expired',
                        'session_id' => $existing->id,
                        'document_version_id' => (int) $lockedVersion->id,
                    ]);
                } else {
                    $this->audit->record('editor_session_accessed', $lockedDocument, $actor, [
                        'source_event_id' => 'editor-session:'.$existing->id.':access:'.Str::uuid(),
                        'session_id' => $existing->id,
                        'document_version_id' => (int) $lockedVersion->id,
                    ]);

                    return $existing;
                }
            }
            $generation = ((int) $this->sessions()->where('organization_id', $lockedDocument->organization_id)
                ->where('document_id', $lockedDocument->id)->where('source_version_id', $lockedVersion->id)
                ->lockForUpdate()->max('generation')) + 1;
            $sessionId = (string) Str::uuid();
            $session = $this->sessions()->create([
                'id' => $sessionId, 'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id, 'source_version_id' => (int) $lockedVersion->id,
                'document_file_id' => (int) $lockedVersion->document_file_id, 'opened_by_user_id' => (int) $actor->id,
                'provider' => $this->editor->provider(), 'mode' => 'edit', 'status' => 'active',
                'generation' => $generation, 'document_key' => $this->documentKey($lockedDocument, $lockedVersion, $sessionId, $generation),
                'source_content_hash' => (string) $lockedVersion->content_hash, 'expires_at' => $expiresAt,
            ]);
            $this->audit->record('editor_session_opened', $lockedDocument, $actor, [
                'source_event_id' => 'editor-session:'.$session->id.':opened',
                'session_id' => $session->id, 'document_version_id' => (int) $lockedVersion->id,
                'generation' => $generation, 'provider' => $this->editor->provider(),
            ]);

            return $session;
        }, 3);

        $sessionExpiry = new DateTimeImmutable((string) $session->expires_at);
        $context = new EditorDocumentContext(
            (string) $session->id, (int) $session->organization_id, (int) $session->document_id,
            (int) $session->source_version_id, (int) $session->document_file_id, (int) $actor->id,
            (int) $session->generation, (string) $session->source_content_hash,
            (string) $version->original_filename, $viewerUrl,
            $callbackBaseUrl.'/api/v1/admin/legal-archive/editor/callback/'.$session->id,
            $sessionExpiry,
        );
        $payload = $this->editor->createSession($context, trim((string) ($actor->name ?? $actor->email ?? $actor->id)));
        if (! hash_equals((string) $session->document_key, $payload->documentKey)) {
            throw new DomainException('legal_document_editor_key_mismatch');
        }

        return $payload;
    }

    public function handleCallback(EditorCallbackInput $input): LegalArchiveDocumentVersion
    {
        $this->editor->verifyCallbackToken($input->token, $input);
        if (in_array($input->status, [1, 3, 5, 7], true)) {
            return $this->acknowledgeTransientStatus($input);
        }
        $replayHash = hash('sha256', $input->replayToken);
        $leaseToken = bin2hex(random_bytes(32));
        $claim = $this->claim($input, $replayHash, $leaseToken);
        if (($claim['expired'] ?? false) === true) {
            throw new DomainException('legal_document_editor_session_expired');
        }
        if ($claim['completed'] instanceof LegalArchiveDocumentVersion) {
            return $claim['completed'];
        }
        $session = $claim['session'];
        if (! $input->requiresSave()) {
            return $this->closeWithoutSave($session, $input, $leaseToken);
        }
        if (! is_string($input->downloadUrl) || $input->downloadUrl === '') {
            $this->releaseClaim($session, $leaseToken, 'download_url_missing');
            throw new DomainException('legal_document_editor_download_url_missing');
        }

        $download = null;
        try {
            $source = $this->versions()->findOrFail((int) $session->source_version_id);
            $extension = strtolower(pathinfo((string) $source->original_filename, PATHINFO_EXTENSION));
            $download = $this->fetcher->fetch($input->downloadUrl, $extension);
            $upload = new UploadedFile($download->path, $download->filename, $download->mimeType, null, true);
            $attempt = new LegalDocumentVersionAttempt(
                'editor-session:'.$session->id,
                $leaseToken,
                fn (LegalArchiveDocument $document, string $token) => $this->assertCallbackLease($session, $document, $token),
            );
            $file = $source->documentFile()->firstOrFail();
            $saved = $this->files->addVersion($file, $upload, new VersionInput(
                versionLabel: 'Редакция из встроенного редактора',
                uploadedByUserId: (int) $session->opened_by_user_id,
                metadata: ['editor_session_id' => (string) $session->id, 'editor_source_version_id' => (int) $source->id],
                makeCurrent: true,
            ), $attempt);

            return $this->complete($session, $saved, $leaseToken);
        } catch (Throwable $error) {
            $this->releaseClaim($session, $leaseToken, $error::class);
            throw $error;
        } finally {
            $download?->cleanup();
        }
    }

    private function claim(EditorCallbackInput $input, string $replayHash, string $leaseToken): array
    {
        return $this->connection->transaction(function () use ($input, $replayHash, $leaseToken): array {
            $candidate = $this->sessions()->find($input->sessionId);
            if (! $candidate instanceof LegalDocumentEditorSession) {
                throw new DomainException('legal_document_editor_session_not_found');
            }
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->source_version_id);
            $session = $this->sessions()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals((string) $session->document_key, $input->documentKey)) {
                throw new DomainException('legal_document_editor_key_mismatch');
            }
            if ($session->status === 'completed') {
                if (! hash_equals((string) $session->callback_replay_hash, $replayHash)) {
                    throw new DomainException('legal_document_editor_callback_conflict');
                }

                return ['session' => $session, 'completed' => $this->versions()->findOrFail((int) $session->saved_version_id)];
            }
            if ($session->expires_at?->isPast()) {
                LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill(['status' => 'expired', 'completed_at' => now()])->save());
                $this->audit->recordForActorId('editor_session_expired', $document, (int) $session->opened_by_user_id, [
                    'source_event_id' => 'editor-session:'.$session->id.':expired',
                    'session_id' => $session->id,
                    'document_version_id' => (int) $session->source_version_id,
                ]);

                return ['session' => $session, 'completed' => null, 'expired' => true];
            }
            if ($session->status === 'processing' && $session->callback_lease_expires_at?->isFuture()) {
                throw new DomainException('legal_document_editor_callback_in_progress');
            }
            if (! in_array($session->status, ['active', 'processing'], true)
                || ($session->callback_replay_hash !== null && ! hash_equals((string) $session->callback_replay_hash, $replayHash))) {
                throw new DomainException('legal_document_editor_callback_conflict');
            }
            if ($input->requiresSave()) {
                $this->assertSourceStillEditable($session, $document);
            }
            LegalDocumentEditorSession::serviceMutation(function () use ($session, $replayHash, $leaseToken): void {
                $session->forceFill([
                    'status' => 'processing', 'callback_replay_hash' => $replayHash,
                    'callback_lease_token_hash' => hash('sha256', $leaseToken),
                    'callback_lease_expires_at' => now()->addMinutes(5),
                    'callback_attempt_count' => ((int) $session->callback_attempt_count) + 1,
                    'failure_code' => null,
                ])->save();
            });

            return ['session' => $session->refresh(), 'completed' => null];
        }, 3);
    }

    private function assertCallbackLease(LegalDocumentEditorSession $candidate, LegalArchiveDocument $document, string $token): void
    {
        $session = $this->sessions()->find($candidate->id);
        if (! $session instanceof LegalDocumentEditorSession || $session->status !== 'processing'
            || $session->expires_at?->isPast()
            || $session->callback_lease_expires_at?->isPast()
            || ! hash_equals((string) $session->callback_lease_token_hash, hash('sha256', $token))) {
            throw new DomainException('legal_document_editor_callback_lease_lost');
        }
        $this->assertSourceStillEditable($session, $document, true);
    }

    private function assertSourceStillEditable(LegalDocumentEditorSession $session, LegalArchiveDocument $document, bool $allowOwnVersion = false): void
    {
        if ((int) $document->current_primary_version_id === (int) $session->source_version_id) {
            $source = $this->versions()->find((int) $session->source_version_id);
            if ($source instanceof LegalArchiveDocumentVersion
                && (bool) $source->is_current
                && $source->processing_status === 'ready'
                && $source->status === 'uploaded'
                && hash_equals((string) $session->source_content_hash, (string) $source->content_hash)) {
                return;
            }
        }
        if ($allowOwnVersion && $document->current_primary_version_id !== null) {
            $current = $this->versions()->find((int) $document->current_primary_version_id);
            if ($current instanceof LegalArchiveDocumentVersion
                && (($current->metadata ?? [])['editor_session_id'] ?? null) === (string) $session->id) {
                return;
            }
        }
        throw new DomainException('legal_document_editor_source_version_changed');
    }

    private function complete(LegalDocumentEditorSession $candidate, LegalArchiveDocumentVersion $saved, string $leaseToken): LegalArchiveDocumentVersion
    {
        return $this->connection->transaction(function () use ($candidate, $saved, $leaseToken): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $lockedSaved = $this->aggregateLock->lockVersion($this->connection, $document, (int) $saved->id);
            $session = $this->sessions()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($session->status === 'completed') {
                return $this->versions()->findOrFail((int) $session->saved_version_id);
            }
            $this->assertCallbackLease($session, $document, $leaseToken);
            LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                'status' => 'completed', 'saved_version_id' => (int) $lockedSaved->id, 'completed_at' => now(),
                'callback_lease_token_hash' => null, 'callback_lease_expires_at' => null,
            ])->save());
            $this->audit->recordForActorId('editor_version_saved', $document, (int) $session->opened_by_user_id, [
                'source_event_id' => 'editor-session:'.$session->id.':saved', 'session_id' => $session->id,
                'source_version_id' => (int) $session->source_version_id, 'saved_version_id' => (int) $lockedSaved->id,
                'content_hash' => (string) $lockedSaved->content_hash,
            ]);

            return $lockedSaved;
        }, 3);
    }

    private function closeWithoutSave(LegalDocumentEditorSession $candidate, EditorCallbackInput $input, string $leaseToken): LegalArchiveDocumentVersion
    {
        return $this->connection->transaction(function () use ($candidate, $input, $leaseToken): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $source = $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->source_version_id);
            $session = $this->sessions()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $this->assertCallbackLease($session, $document, $leaseToken);
            LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                'status' => 'closed', 'completed_at' => now(), 'callback_lease_token_hash' => null,
                'callback_lease_expires_at' => null, 'failure_code' => 'onlyoffice_status_'.$input->status,
            ])->save());

            return $source;
        }, 3);
    }

    private function acknowledgeTransientStatus(EditorCallbackInput $input): LegalArchiveDocumentVersion
    {
        $result = $this->connection->transaction(function () use ($input): ?LegalArchiveDocumentVersion {
            $candidate = $this->sessions()->find($input->sessionId);
            if (! $candidate instanceof LegalDocumentEditorSession) {
                throw new DomainException('legal_document_editor_session_not_found');
            }
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $source = $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->source_version_id);
            $session = $this->sessions()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals((string) $session->document_key, $input->documentKey)
                || ! in_array($session->status, ['active', 'processing'], true)) {
                throw new DomainException('legal_document_editor_session_expired');
            }
            if ($session->expires_at?->isPast()) {
                LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill(['status' => 'expired', 'completed_at' => now()])->save());
                $this->audit->recordForActorId('editor_session_expired', $document, (int) $session->opened_by_user_id, [
                    'source_event_id' => 'editor-session:'.$session->id.':expired',
                    'session_id' => $session->id,
                    'document_version_id' => (int) $session->source_version_id,
                ]);

                return null;
            }
            if (in_array($input->status, [3, 7], true) && $session->status === 'active') {
                LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                    'failure_code' => 'onlyoffice_status_'.$input->status,
                ])->save());
            }

            return $source;
        }, 3);

        if (! $result instanceof LegalArchiveDocumentVersion) {
            throw new DomainException('legal_document_editor_session_expired');
        }

        return $result;
    }

    private function releaseClaim(LegalDocumentEditorSession $candidate, string $leaseToken, string $failure): void
    {
        $this->connection->transaction(function () use ($candidate, $leaseToken, $failure): void {
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->source_version_id);
            $session = $this->sessions()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $session instanceof LegalDocumentEditorSession || $session->status !== 'processing'
                || ! hash_equals((string) $session->callback_lease_token_hash, hash('sha256', $leaseToken))) {
                return;
            }
            $expired = $session->expires_at?->isPast() === true;
            LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                'status' => $expired ? 'expired' : 'active',
                'completed_at' => $expired ? now() : null,
                'callback_lease_token_hash' => null, 'callback_lease_expires_at' => null,
                'failure_code' => substr($failure, 0, 120),
            ])->save());
            if ($expired) {
                $this->audit->recordForActorId('editor_session_expired', $document, (int) $session->opened_by_user_id, [
                    'source_event_id' => 'editor-session:'.$session->id.':expired',
                    'session_id' => $session->id,
                    'document_version_id' => (int) $session->source_version_id,
                ]);
            }
        }, 3);
    }

    private function assertEditable(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version): void
    {
        if ((int) $document->current_primary_version_id !== (int) $version->id || ! (bool) $version->is_current
            || $version->processing_status !== 'ready' || $version->status !== 'uploaded'
            || preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) !== 1
            || $this->connection->table('legal_workflow_instances')->where('document_id', $document->id)->where('status', 'in_progress')->exists()
            || $this->connection->table('legal_signature_requests')->where('document_id', $document->id)->where('status', 'pending')->exists()) {
            throw new DomainException('legal_document_editor_version_not_editable');
        }
    }

    private function documentForVersion(LegalArchiveDocumentVersion $version): LegalArchiveDocument
    {
        $document = $version->documentFile?->document ?? $version->document;
        if (! $document instanceof LegalArchiveDocument || (int) $document->organization_id !== (int) $version->organization_id) {
            throw new DomainException('legal_document_editor_version_not_found');
        }

        return $document;
    }

    private function documentKey(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version, string $sessionId, int $generation): string
    {
        return $document->id.'.'.substr(hash('sha256', implode(':', [$document->organization_id, $document->id, $version->id,
            $version->content_hash, $sessionId, $generation])), 0, 48);
    }

    private function sessions(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalDocumentEditorSession)->setConnection($this->connection->getName())->newQuery();
    }

    private function versions(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalArchiveDocumentVersion)->setConnection($this->connection->getName())->newQuery();
    }
}
