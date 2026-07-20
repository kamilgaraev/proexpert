<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentEditorParticipant;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentEditorSave;
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

    public function open(
        LegalArchiveDocumentVersion $version,
        User $actor,
        string $mode = 'edit',
        bool $upgradeMode = false,
    ): EditorSessionPayload {
        if (! in_array($mode, ['view', 'review', 'edit'], true)) {
            throw new DomainException('legal_document_editor_mode_invalid');
        }
        $document = $this->documentForVersion($version);
        $this->authorizer->authorize($actor, $document, LegalDocumentAbility::VIEW->value);
        $callbackBaseUrl = '';
        if ($this->editor->enabled()) {
            $callbackBaseUrl = rtrim((string) config('legal-document-editor.callback_base_url'), '/');
            if (! str_starts_with($callbackBaseUrl, 'https://')) {
                throw new DomainException('legal_document_editor_configuration_invalid');
            }
        }
        $sourceTtlMinutes = max(1, min(15, (int) config('legal-document-editor.source_url_ttl_minutes', 10)));
        $viewerUrl = $this->downloads->temporaryUrl($version, $actor, 'preview', $sourceTtlMinutes);
        $sessionMinutes = max(5, min(720, (int) config('legal-document-editor.session_ttl_minutes', 120)));
        $expiresAt = new DateTimeImmutable("+{$sessionMinutes} minutes");
        if (! $this->editor->enabled()) {
            return new EditorSessionPayload(false, 'viewer', '', '', null, null, [], $expiresAt, $viewerUrl, 'not_configured');
        }

        if ($mode === 'view') {
            return $this->editor->createSession(new EditorDocumentContext(
                (string) Str::uuid(), (int) $document->organization_id, (int) $document->id,
                (int) $version->id, (int) $version->document_file_id, (int) $actor->id, 0,
                (string) $version->content_hash, (string) $version->original_filename, $viewerUrl, '', $expiresAt, 'view',
            ), trim((string) ($actor->name ?? $actor->email ?? $actor->id)));
        }

        $this->authorizer->authorize($actor, $document, $mode === 'review'
            ? LegalDocumentAbility::COMMENT->value
            : LegalDocumentAbility::EDIT->value);
        $session = $this->connection->transaction(function () use ($version, $document, $actor, $expiresAt, $mode, $upgradeMode): LegalDocumentEditorSession {
            $lockedDocument = $this->aggregateLock->lockDocument($this->connection, (int) $document->organization_id, (int) $document->id);
            $lockedVersion = $this->aggregateLock->lockVersion($this->connection, $lockedDocument, (int) $version->id);
            $this->assertEditable($lockedDocument, $lockedVersion);
            $this->authorizer->authorize($actor, $lockedDocument, $mode === 'review'
                ? LegalDocumentAbility::COMMENT->value
                : LegalDocumentAbility::EDIT->value);
            $existing = $this->sessions()->where('organization_id', $lockedDocument->organization_id)
                ->where('document_id', $lockedDocument->id)->where('source_version_id', $lockedVersion->id)
                ->whereIn('status', ['active', 'processing'])->lockForUpdate()->first();
            if ($existing instanceof LegalDocumentEditorSession) {
                if ($existing->expires_at?->isPast()) {
                    $this->failInFlightSaves($existing);
                    LegalDocumentEditorSession::serviceMutation(function () use ($existing): void {
                        $existing->forceFill(['status' => 'expired', 'completed_at' => now()])->save();
                    });
                    $this->audit->recordForActorId('editor_session_expired', $lockedDocument, (int) $existing->opened_by_user_id, [
                        'source_event_id' => 'editor-session:'.$existing->id.':expired',
                        'session_id' => $existing->id,
                        'document_version_id' => (int) $lockedVersion->id,
                    ]);
                } else {
                    if ((int) $existing->opened_by_user_id !== (int) $actor->id) {
                        throw new DomainException('legal_document_editor_actor_conflict');
                    }
                    if ((string) $existing->mode === $mode) {
                        $this->recordParticipant($existing, $actor, $this->abilityForMode($mode));
                        $this->audit->record('editor_session_accessed', $lockedDocument, $actor, [
                            'source_event_id' => 'editor-session:'.$existing->id.':access:'.Str::uuid(),
                            'session_id' => $existing->id,
                            'document_version_id' => (int) $lockedVersion->id,
                        ]);

                        return $existing;
                    }
                    if (! $upgradeMode || $mode !== 'edit' || (string) $existing->mode !== 'review'
                        || $this->saves()->where('editor_session_id', $existing->id)
                            ->whereIn('state', ['reserved', 'processing'])->exists()) {
                        throw new DomainException('legal_document_editor_mode_conflict');
                    }
                    LegalDocumentEditorSession::serviceMutation(fn () => $existing->forceFill([
                        'status' => 'closed',
                        'completed_at' => now(),
                        'failure_code' => 'mode_upgraded',
                    ])->save());
                    $this->audit->record('editor_session_mode_upgraded', $lockedDocument, $actor, [
                        'source_event_id' => 'editor-session:'.$existing->id.':mode-upgraded',
                        'session_id' => $existing->id,
                        'from_mode' => (string) $existing->mode,
                        'to_mode' => $mode,
                    ]);
                }
            }
            $latestGeneration = $this->sessions()->where('organization_id', $lockedDocument->organization_id)
                ->where('document_id', $lockedDocument->id)->where('source_version_id', $lockedVersion->id)
                ->orderByDesc('generation')->value('generation');
            $generation = ((int) $latestGeneration) + 1;
            $sessionId = (string) Str::uuid();
            $session = $this->sessions()->create([
                'id' => $sessionId, 'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id, 'source_version_id' => (int) $lockedVersion->id,
                'document_file_id' => (int) $lockedVersion->document_file_id, 'opened_by_user_id' => (int) $actor->id,
                'provider' => $this->editor->provider(), 'mode' => $mode, 'status' => 'active',
                'generation' => $generation, 'document_key' => $this->documentKey($lockedDocument, $lockedVersion, $sessionId, $generation),
                'source_content_hash' => (string) $lockedVersion->content_hash, 'expires_at' => $expiresAt,
            ]);
            $this->recordParticipant($session, $actor, $this->abilityForMode($mode));
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
            $callbackBaseUrl.'/api/v1/legal-document-editor/callback/'.$session->id,
            $sessionExpiry,
            (string) $session->mode,
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
        if (($claim['completed'] ?? null) instanceof LegalArchiveDocumentVersion) {
            return $claim['completed'];
        }
        $session = $claim['session'];
        $save = $claim['save'];
        if (! is_string($input->downloadUrl) || $input->downloadUrl === '') {
            $this->releaseClaim($save, $leaseToken, 'download_url_missing');
            throw new DomainException('legal_document_editor_download_url_missing');
        }

        $download = null;
        try {
            $source = $this->versions()->findOrFail((int) $session->source_version_id);
            $extension = strtolower(pathinfo((string) $source->original_filename, PATHINFO_EXTENSION));
            $download = $this->fetcher->fetch($input->downloadUrl, $extension);
            $upload = new UploadedFile($download->path, $download->filename, $download->mimeType, null, true);
            $attempt = new LegalDocumentVersionAttempt(
                (string) $save->operation_id,
                $leaseToken,
                fn (LegalArchiveDocument $document, string $token) => $this->assertCallbackLease($save, $document, $token),
                heartbeatCallback: fn (string $token) => $this->renewSaveLease($save, $token),
                completionCallback: fn (LegalArchiveDocumentVersion $version, string $token) => $this->completeSave($save, $version, $token),
            );
            $file = $source->documentFile()->firstOrFail();
            $saved = $this->files->addVersion($file, $upload, new VersionInput(
                versionLabel: trans_message('legal_archive.messages.editor_version_label'),
                uploadedByUserId: (int) $session->opened_by_user_id,
                metadata: [
                    'editor_session_id' => (string) $session->id,
                    'editor_source_version_id' => (int) $source->id,
                    'editor_save_generation' => (int) $save->save_generation,
                    'editor_actor_user_id' => (int) $session->opened_by_user_id,
                ],
                makeCurrent: true,
            ), $attempt);

            return $saved;
        } catch (Throwable $error) {
            $this->releaseClaim($save, $leaseToken, $error::class);
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
            $save = $this->saves()->where('editor_session_id', $session->id)
                ->where('replay_hash', $replayHash)->lockForUpdate()->first();
            if ($save instanceof LegalDocumentEditorSave && $save->state === 'completed') {
                $completed = $save->saved_version_id === null
                    ? $this->versions()->findOrFail((int) $session->source_version_id)
                    : $this->versions()->findOrFail((int) $save->saved_version_id);

                return ['session' => $session, 'save' => $save, 'completed' => $completed];
            }
            if ($save instanceof LegalDocumentEditorSave && $save->state === 'failed') {
                throw new DomainException('legal_document_editor_callback_failed_replay');
            }
            $this->reauthorizeSessionActor($session, $document);
            if ($session->expires_at?->isPast()) {
                $this->failInFlightSaves($session);
                LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill(['status' => 'expired', 'completed_at' => now()])->save());
                $this->audit->recordForActorId('editor_session_expired', $document, (int) $session->opened_by_user_id, [
                    'source_event_id' => 'editor-session:'.$session->id.':expired',
                    'session_id' => $session->id,
                    'document_version_id' => (int) $session->source_version_id,
                ]);

                return ['session' => $session, 'completed' => null, 'expired' => true];
            }
            if (! in_array($session->status, ['active', 'processing'], true)) {
                throw new DomainException('legal_document_editor_callback_conflict');
            }
            if ($input->requiresSave()) {
                (new LegalDocumentEditGuard($this->connection))->assertVersionMutationAllowed($document, (string) $session->id);
                $this->assertSourceStillEditable($session, $document, true);
            }
            if ($save instanceof LegalDocumentEditorSave
                && $save->state === 'processing'
                && $save->lease_expires_at?->isFuture()) {
                throw new DomainException('legal_document_editor_callback_in_progress');
            }
            if (! $save instanceof LegalDocumentEditorSave) {
                $terminal = in_array($input->status, [2, 4], true);
                $activeTerminal = $this->saves()->where('editor_session_id', $session->id)
                    ->where('terminal', true)->whereIn('state', ['reserved', 'processing', 'completed'])
                    ->lockForUpdate()->first();
                if ($activeTerminal instanceof LegalDocumentEditorSave) {
                    throw new DomainException('legal_document_editor_callback_conflict');
                }
                $superseded = $this->saves()->where('editor_session_id', $session->id)
                    ->where('terminal', true)->where('state', 'failed')
                    ->orderByDesc('save_generation')->lockForUpdate()->first();
                if ($superseded instanceof LegalDocumentEditorSave && ! $terminal) {
                    throw new DomainException('legal_document_editor_callback_conflict');
                }
                $generation = (int) $session->next_save_generation;
                $save = $this->saves()->create([
                    'id' => (string) Str::uuid(),
                    'organization_id' => (int) $session->organization_id,
                    'document_id' => (int) $session->document_id,
                    'editor_session_id' => (string) $session->id,
                    'source_version_id' => (int) $session->source_version_id,
                    'document_file_id' => (int) $session->document_file_id,
                    'save_generation' => $generation,
                    'callback_status' => $input->status,
                    'replay_hash' => $replayHash,
                    'supersedes_save_id' => $superseded?->id,
                    'operation_id' => (string) Str::uuid(),
                    'state' => 'reserved',
                    'terminal' => $terminal,
                ]);
                LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                    'next_save_generation' => $generation + 1,
                ])->save());
            }
            if ($input->status === 4) {
                LegalDocumentEditorSave::serviceMutation(fn () => $save->forceFill([
                    'state' => 'completed', 'completed_at' => now(),
                ])->save());
                $session->refresh();

                return [
                    'session' => $session->refresh(),
                    'save' => $save->refresh(),
                    'completed' => $this->versions()->findOrFail((int) $session->source_version_id),
                ];
            }
            $isForceSave = $input->status === 6;
            LegalDocumentEditorSave::serviceMutation(fn () => $save->forceFill([
                'state' => 'processing',
                'lease_owner_hash' => hash('sha256', $leaseToken),
                'lease_expires_at' => now()->addMinutes(5),
                'failed_at' => null,
            ])->save());
            if ($isForceSave && (bool) $save->terminal) {
                throw new DomainException('legal_document_editor_force_save_terminal_invalid');
            }
            if ($input->status === 2 && $session->status === 'active') {
                LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                    'status' => 'processing',
                ])->save());
            }

            return ['session' => $session->refresh(), 'save' => $save->refresh(), 'completed' => null];
        }, 3);
    }

    private function assertCallbackLease(LegalDocumentEditorSave $candidate, LegalArchiveDocument $document, string $token): void
    {
        $save = $this->saves()->find($candidate->id);
        $session = $this->sessions()->find($candidate->editor_session_id);
        if (! $save instanceof LegalDocumentEditorSave || ! $session instanceof LegalDocumentEditorSession
            || $save->state !== 'processing' || $session->expires_at?->isPast()
            || $save->lease_expires_at?->isPast()
            || ! hash_equals((string) $save->lease_owner_hash, hash('sha256', $token))) {
            throw new DomainException('legal_document_editor_callback_lease_lost');
        }
        (new LegalDocumentEditGuard($this->connection))->assertVersionMutationAllowed($document, (string) $session->id);
        $this->assertSourceStillEditable($session, $document, true);
    }

    private function renewSaveLease(LegalDocumentEditorSave $candidate, string $token): void
    {
        $this->connection->transaction(function () use ($candidate, $token): void {
            $session = $this->sessions()->whereKey($candidate->editor_session_id)->lockForUpdate()->first();
            $save = $this->saves()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $save instanceof LegalDocumentEditorSave || ! $session instanceof LegalDocumentEditorSession
                || $save->state !== 'processing'
                || ! in_array($session->status, ['active', 'processing'], true)
                || $session->expires_at?->isPast()
                || ! hash_equals((string) $save->lease_owner_hash, hash('sha256', $token))) {
                throw new DomainException('legal_document_editor_callback_lease_lost');
            }
            LegalDocumentEditorSave::serviceMutation(fn () => $save->forceFill([
                'lease_expires_at' => now()->addMinutes(5),
            ])->save());
        }, 3);
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

    private function completeSave(LegalDocumentEditorSave $candidate, LegalArchiveDocumentVersion $saved, string $leaseToken): void
    {
        $complete = function () use ($candidate, $saved, $leaseToken): void {
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $lockedSaved = $this->aggregateLock->lockVersion($this->connection, $document, (int) $saved->id);
            $session = $this->sessions()->whereKey($candidate->editor_session_id)->lockForUpdate()->firstOrFail();
            $save = $this->saves()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($save->state === 'completed') {
                return;
            }
            $this->reauthorizeSessionActor($session, $document);
            $this->assertCallbackLease($save, $document, $leaseToken);
            LegalDocumentEditorSave::serviceMutation(fn () => $save->forceFill([
                'state' => 'completed',
                'saved_version_id' => (int) $lockedSaved->id,
                'content_hash' => (string) $lockedSaved->content_hash,
                'lease_owner_hash' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
            ])->save());
            $session->refresh();
            $this->audit->recordForActorId('editor_version_saved', $document, (int) $session->opened_by_user_id, [
                'source_event_id' => 'editor-session:'.$session->id.':save:'.$save->save_generation,
                'session_id' => $session->id,
                'source_version_id' => (int) $session->source_version_id, 'saved_version_id' => (int) $lockedSaved->id,
                'save_generation' => (int) $save->save_generation,
                'callback_status' => (int) $save->callback_status,
                'supersedes_save_id' => $save->supersedes_save_id,
                'actor_user_id' => (int) $session->opened_by_user_id,
                'content_hash' => (string) $lockedSaved->content_hash,
            ]);
        };

        if ($this->connection->transactionLevel() > 0) {
            $complete();

            return;
        }
        $this->connection->transaction($complete, 3);
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
                $this->failInFlightSaves($session);
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

    private function releaseClaim(LegalDocumentEditorSave $candidate, string $leaseToken, string $failure): void
    {
        $this->connection->transaction(function () use ($candidate, $leaseToken, $failure): void {
            $document = $this->aggregateLock->lockDocument($this->connection, (int) $candidate->organization_id, (int) $candidate->document_id);
            $this->aggregateLock->lockVersion($this->connection, $document, (int) $candidate->source_version_id);
            $session = $this->sessions()->whereKey($candidate->editor_session_id)->lockForUpdate()->first();
            $save = $this->saves()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $save instanceof LegalDocumentEditorSave || ! $session instanceof LegalDocumentEditorSession
                || $save->state !== 'processing'
                || ! hash_equals((string) $save->lease_owner_hash, hash('sha256', $leaseToken))) {
                return;
            }
            $expired = $session->expires_at?->isPast() === true;
            LegalDocumentEditorSave::serviceMutation(fn () => $save->forceFill([
                'state' => 'failed',
                'lease_owner_hash' => null,
                'lease_expires_at' => null,
                'failed_at' => now(),
            ])->save());
            if (! in_array((string) $session->status, ['active', 'processing'], true)) {
                return;
            }
            if ($expired) {
                $this->failInFlightSaves($session);
            }
            LegalDocumentEditorSession::serviceMutation(fn () => $session->forceFill([
                'status' => $expired ? 'expired' : ($session->status === 'processing' ? 'active' : $session->status),
                'completed_at' => $expired ? now() : null,
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
        (new LegalDocumentEditGuard($this->connection))->assertEditorOpenAllowed($document, $version);
    }

    private function documentForVersion(LegalArchiveDocumentVersion $version): LegalArchiveDocument
    {
        $document = $version->documentFile?->document ?? $version->document;
        if (! $document instanceof LegalArchiveDocument || (int) $document->organization_id !== (int) $version->organization_id) {
            throw new DomainException('legal_document_editor_version_not_found');
        }

        return $document;
    }

    private function reauthorizeSessionActor(LegalDocumentEditorSession $session, LegalArchiveDocument $document): void
    {
        $actor = (new User)->setConnection($this->connection->getName())->newQuery()
            ->whereKey((int) $session->opened_by_user_id)
            ->where('is_active', true)
            ->first();
        if (! $actor instanceof User) {
            throw new DomainException('legal_document_editor_actor_inactive');
        }
        $actor->forceFill(['current_organization_id' => (int) $session->organization_id]);
        $participants = $this->participants()->where('editor_session_id', $session->id)->get();
        $participant = $participants->first();
        $requiredAbility = $this->abilityForMode((string) $session->mode);
        if ($participants->count() !== 1
            || ! $participant instanceof LegalDocumentEditorParticipant
            || (int) $participant->user_id !== (int) $session->opened_by_user_id
            || ! hash_equals($requiredAbility, (string) $participant->required_ability)) {
            throw new DomainException('legal_document_editor_participant_invalid');
        }
        $this->authorizer->authorize($actor, $document, $requiredAbility);
    }

    private function documentKey(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version, string $sessionId, int $generation): string
    {
        return $version->id.'.'.substr(hash('sha256', implode(':', [$document->organization_id, $document->id, $version->id,
            $version->content_hash, $sessionId, $generation])), 0, 48);
    }

    private function recordParticipant(LegalDocumentEditorSession $session, User $actor, string $requiredAbility): void
    {
        $actorKey = hash('sha256', $session->organization_id.'|user|'.$actor->id);
        $this->participants()->firstOrCreate([
            'editor_session_id' => (string) $session->id,
            'actor_key' => $actorKey,
        ], [
            'id' => (string) Str::uuid(),
            'organization_id' => (int) $session->organization_id,
            'user_id' => (int) $actor->id,
            'provider_user_id' => (string) $actor->id,
            'required_ability' => $requiredAbility,
            'joined_at' => now(),
        ]);
    }

    private function abilityForMode(string $mode): string
    {
        return match ($mode) {
            'review' => LegalDocumentAbility::COMMENT->value,
            'edit' => LegalDocumentAbility::EDIT->value,
            default => throw new DomainException('legal_document_editor_mode_invalid'),
        };
    }

    private function failInFlightSaves(LegalDocumentEditorSession $session): void
    {
        $inFlight = $this->saves()->where('editor_session_id', $session->id)
            ->whereIn('state', ['reserved', 'processing'])
            ->orderBy('save_generation')->lockForUpdate()->get();
        foreach ($inFlight as $save) {
            if (! $save instanceof LegalDocumentEditorSave) {
                continue;
            }
            LegalDocumentEditorSave::serviceMutation(fn () => $save->forceFill([
                'state' => 'failed',
                'lease_owner_hash' => null,
                'lease_expires_at' => null,
                'failed_at' => now(),
            ])->save());
        }
    }

    private function sessions(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalDocumentEditorSession)->setConnection($this->connection->getName())->newQuery();
    }

    private function versions(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalArchiveDocumentVersion)->setConnection($this->connection->getName())->newQuery();
    }

    private function saves(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalDocumentEditorSave)->setConnection($this->connection->getName())->newQuery();
    }

    private function participants(): \Illuminate\Database\Eloquent\Builder
    {
        return (new LegalDocumentEditorParticipant)->setConnection($this->connection->getName())->newQuery();
    }
}
