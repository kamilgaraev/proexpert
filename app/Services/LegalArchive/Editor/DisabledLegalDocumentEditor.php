<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use DomainException;

final class DisabledLegalDocumentEditor implements LegalDocumentEditor
{
    public function enabled(): bool
    {
        return false;
    }

    public function provider(): string
    {
        return 'disabled';
    }

    public function createSession(EditorDocumentContext $context, string $actorName): EditorSessionPayload
    {
        throw new DomainException('legal_document_editor_disabled');
    }

    public function verifyCallbackToken(string $token, EditorCallbackInput $input): void
    {
        throw new DomainException('legal_document_editor_disabled');
    }
}
