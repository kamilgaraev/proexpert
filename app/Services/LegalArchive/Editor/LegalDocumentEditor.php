<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

interface LegalDocumentEditor
{
    public function enabled(): bool;

    public function provider(): string;

    public function createSession(EditorDocumentContext $context, string $actorName): EditorSessionPayload;

    public function verifyCallbackToken(string $token, EditorCallbackInput $input): void;
}
