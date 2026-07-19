<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class LegalDocumentDownloadService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly AuthorizationService $authorization,
        private readonly LegalDocumentFilePolicy $policy,
        private readonly LoggerInterface $logger,
    ) {}

    public function temporaryUrl(LegalArchiveDocumentVersion $version, User $actor, string $purpose): string
    {
        $organizationId = (int) $version->organization_id;

        try {
            $this->policy->assertDownloadAllowed($version, $actor, $purpose);

            if (! $this->authorization->can($actor, 'legal_archive.view', ['organization_id' => $organizationId])) {
                throw new AuthorizationException($this->message('file_access_denied'));
            }
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

        $organization = $version->organization;
        if (! $organization instanceof Organization) {
            $organization = new Organization;
            $organization->forceFill(['id' => $organizationId]);
        }

        $url = $this->fileService->temporaryUrl(
            (string) $version->file_path,
            $this->temporaryUrlMinutes(),
            $organization,
        );

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('legal_document_temporary_url_failed');
        }

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

    private function message(string $key): string
    {
        if (Container::getInstance()->bound('translator')) {
            return trans_message("legal_archive.messages.{$key}");
        }

        return "legal_archive.messages.{$key}";
    }
}
