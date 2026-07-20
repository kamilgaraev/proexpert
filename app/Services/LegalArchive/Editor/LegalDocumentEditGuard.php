<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use DomainException;
use Illuminate\Database\ConnectionInterface;

final readonly class LegalDocumentEditGuard
{
    public function __construct(private ConnectionInterface $connection) {}

    public function assertEditorOpenAllowed(LegalArchiveDocument $document, LegalArchiveDocumentVersion $version): void
    {
        $this->assertMutable($document, null, true);
        if ((int) $document->current_primary_version_id !== (int) $version->id
            || ! (bool) $version->is_current
            || $version->processing_status !== 'ready'
            || $version->status !== 'uploaded'
            || preg_match('/^[a-f0-9]{64}$/D', (string) $version->content_hash) !== 1) {
            throw new DomainException('legal_document_editor_version_not_editable');
        }
    }

    public function assertVersionMutationAllowed(LegalArchiveDocument $document, ?string $editorSessionId = null): void
    {
        $this->assertMutable($document, $editorSessionId, false);
    }

    public function assertWorkflowSubmissionAllowed(LegalArchiveDocument $document): void
    {
        $this->assertDocumentStateMutable($document);
        if ($this->has('legal_signature_requests')
            && $this->connection->table('legal_signature_requests')->where('document_id', $document->id)->where('status', 'pending')->exists()) {
            throw new DomainException('legal_document_active_signature_exists');
        }
        $this->assertNoActiveEditor($document, null);
    }

    public function assertSignatureAllowed(LegalArchiveDocument $document): void
    {
        $this->assertNoActiveEditor($document, null);
    }

    private function assertMutable(LegalArchiveDocument $document, ?string $editorSessionId, bool $ignoreEditorSessions): void
    {
        $this->assertDocumentStateMutable($document);
        if ($this->has('legal_workflow_instances')
            && $this->connection->table('legal_workflow_instances')->where('document_id', $document->id)->where('status', 'in_progress')->exists()) {
            throw new DomainException('legal_document_active_workflow_exists');
        }
        if ($this->has('legal_signature_requests')
            && $this->connection->table('legal_signature_requests')->where('document_id', $document->id)->where('status', 'pending')->exists()) {
            throw new DomainException('legal_document_active_signature_exists');
        }
        if (! $ignoreEditorSessions) {
            $this->assertNoActiveEditor($document, $editorSessionId);
        }
    }

    private function assertDocumentStateMutable(LegalArchiveDocument $document): void
    {
        if ((string) $document->approval_status === 'approved'
            || in_array((string) $document->lifecycle_status, ['approved', 'signing', 'partially_signed', 'signed', 'active', 'completed', 'archived'], true)) {
            throw new DomainException('legal_document_editing_frozen');
        }
    }

    private function assertNoActiveEditor(LegalArchiveDocument $document, ?string $editorSessionId): void
    {
        if ($this->has('legal_document_editor_sessions')) {
            $active = $this->connection->table('legal_document_editor_sessions')
                ->where('document_id', $document->id)
                ->whereIn('status', ['active', 'processing'])
                ->where('expires_at', '>', now());
            if ($editorSessionId !== null) {
                $active->where('id', '<>', $editorSessionId);
            }
            if ($active->exists()) {
                throw new DomainException('legal_document_active_editor_exists');
            }
        }
    }

    private function has(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }
}
