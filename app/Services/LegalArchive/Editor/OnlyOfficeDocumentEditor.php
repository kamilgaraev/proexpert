<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use DomainException;

final class OnlyOfficeDocumentEditor implements LegalDocumentEditor
{
    private array $configuration;

    public function __construct(?array $configuration = null)
    {
        $this->configuration = $configuration ?? (array) config('legal-document-editor', []);
        if (($this->configuration['enabled'] ?? false) === true) {
            $url = (string) ($this->configuration['url'] ?? '');
            $secret = (string) ($this->configuration['jwt_secret'] ?? '');
            if (! str_starts_with($url, 'https://') || strlen($secret) < 32) {
                throw new DomainException('legal_document_editor_configuration_invalid');
            }
        }
    }

    public function enabled(): bool
    {
        return ($this->configuration['enabled'] ?? false) === true;
    }

    public function provider(): string
    {
        return 'onlyoffice';
    }

    public function createSession(EditorDocumentContext $context, string $actorName): EditorSessionPayload
    {
        if (! $this->enabled()) {
            throw new DomainException('legal_document_editor_disabled');
        }
        $extension = strtolower(pathinfo($context->filename, PATHINFO_EXTENSION));
        $documentType = in_array($extension, ['xls', 'xlsx', 'ods'], true) ? 'cell' : 'word';
        $key = $context->versionId.'.'.substr(hash('sha256', implode(':', [
            $context->organizationId, $context->documentId, $context->versionId,
            $context->contentHash, $context->sessionId, $context->generation,
        ])), 0, 48);
        $configuration = [
            'document' => [
                'fileType' => $extension,
                'key' => $key,
                'title' => $context->filename,
                'url' => $context->sourceUrl,
                'permissions' => [
                    'edit' => $context->mode === 'edit',
                    'review' => $context->mode === 'review',
                    'comment' => in_array($context->mode, ['edit', 'review'], true),
                    'download' => false,
                    'print' => false,
                ],
            ],
            'documentType' => $documentType,
            'editorConfig' => [
                ...($context->callbackUrl === '' ? [] : ['callbackUrl' => $context->callbackUrl]),
                'lang' => 'ru',
                'mode' => $context->mode === 'view' ? 'view' : 'edit',
                'user' => ['id' => (string) $context->actorId, 'name' => $actorName],
            ],
            'exp' => $context->expiresAt->getTimestamp(),
        ];
        $token = OnlyOfficeJwt::encode($configuration, (string) $this->configuration['jwt_secret']);
        $configuration['token'] = $token;

        return new EditorSessionPayload(
            true, $context->mode, $key, $context->versionId.'.',
            rtrim((string) $this->configuration['url'], '/'), $token,
            $configuration, $context->expiresAt,
        );
    }

    public function verifyCallbackToken(string $token, EditorCallbackInput $input): void
    {
        if (! $this->enabled()) {
            throw new DomainException('legal_document_editor_disabled');
        }
        $claims = OnlyOfficeJwt::decode($token, (string) $this->configuration['jwt_secret']);
        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }
        $key = $claims['key'] ?? $claims['document']['key'] ?? null;
        if (! is_string($key) || ! hash_equals($input->documentKey, $key)) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }
        if (! isset($claims['status']) || (int) $claims['status'] !== $input->status
            || ($input->requiresSave() && (! isset($claims['url']) || (string) $claims['url'] !== (string) $input->downloadUrl))
            || (isset($claims['url']) && (string) $claims['url'] !== (string) $input->downloadUrl)) {
            throw new DomainException('legal_document_editor_callback_unauthenticated');
        }
    }
}
