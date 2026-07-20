<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LegalDocumentDownloadService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalDocumentFilePolicy $policy,
        private readonly LoggerInterface $logger,
        private readonly LegalDocumentAudit $audit,
    ) {}

    public function temporaryUrl(LegalArchiveDocumentVersion $version, User $actor, string $purpose): string
    {
        $organizationId = (int) $version->organization_id;

        try {
            $this->policy->assertDownloadAllowed($version, $actor, $purpose);
            $document = $version->documentFile?->document;
            if (! $document instanceof LegalArchiveDocument) {
                throw new AuthorizationException($this->message('file_access_denied'));
            }
            $this->access->authorize($actor, $document, $purpose === 'download' ? 'download' : 'view');
        } catch (AuthorizationException $exception) {
            $this->logger->warning('legal_archive.file_access_denied', [
                'actor_id' => $actor->id,
                'organization_id' => $organizationId,
                'document_id' => $version->document_id,
                'document_file_id' => $version->document_file_id,
                'version_id' => $version->id,
                'purpose' => $purpose,
            ]);

            throw $exception;
        }

        $document = $version->documentFile?->document;
        if (! $document instanceof LegalArchiveDocument) {
            $documentModel = new LegalArchiveDocument;
            $documentModel->setConnection($version->getConnectionName());
            $document = $documentModel->newQuery()
                ->forOrganization($organizationId)
                ->find($version->document_id);
        }
        if (! $document instanceof LegalArchiveDocument) {
            throw new RuntimeException('legal_document_not_found_for_audit');
        }
        $organization = $version->organization;
        if (! $organization instanceof Organization) {
            $organization = new Organization;
            $organization->forceFill(['id' => $organizationId]);
        }

        $url = $this->fileService->temporaryUrl(
            (string) $version->file_path,
            $this->temporaryUrlMinutes(),
            $organization,
            [
                'ResponseContentType' => (string) ($version->mime_type ?: 'application/octet-stream'),
                'ResponseContentDisposition' => ($purpose === 'download' ? 'attachment' : 'inline')
                    .'; filename="'.$this->safeFilename((string) $version->original_filename).'"',
            ],
        );

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('legal_document_temporary_url_failed');
        }

        $issuanceEvent = $purpose.'_url_issued';
        $this->audit->record($issuanceEvent, $document, $actor, [
            'version_id' => (int) $version->id,
            'document_file_id' => (int) $version->document_file_id,
            'source_event_id' => $issuanceEvent.':'.(string) $version->id.':'.(string) Str::uuid(),
        ]);

        $this->logger->info('legal_archive.file_access_granted', [
            'actor_id' => $actor->id,
            'organization_id' => $organizationId,
            'document_id' => $version->document_id,
            'document_file_id' => $version->document_file_id,
            'version_id' => $version->id,
            'purpose' => $purpose,
        ]);

        return $url;
    }

    private function temporaryUrlMinutes(): int
    {
        if (Container::getInstance()->bound('config')) {
            return max(1, (int) config('file-uploads.legal_archive.temporary_url_minutes', 5));
        }

        return 5;
    }

    private function safeFilename(string $filename): string
    {
        $filename = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename));

        return $filename !== '' ? substr($filename, 0, 180) : 'document';
    }

    private function message(string $key): string
    {
        if (Container::getInstance()->bound('translator')) {
            return trans_message("legal_archive.messages.{$key}");
        }

        return "legal_archive.messages.{$key}";
    }
}
