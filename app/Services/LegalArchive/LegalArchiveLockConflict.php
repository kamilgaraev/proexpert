<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use RuntimeException;

final class LegalArchiveLockConflict extends RuntimeException
{
    private function __construct(
        public readonly int $currentLockVersion,
        public readonly string $aggregateKind,
        public readonly string $aggregateId,
        public readonly ?int $documentId,
        public readonly string $refreshUrl,
        public readonly string $etag,
    ) {
        parent::__construct('legal_archive_lock_conflict');
    }

    public static function forDocument(int $documentId, int $currentLockVersion): self
    {
        return new self(
            $currentLockVersion,
            'legal_document',
            (string) $documentId,
            $documentId,
            "/api/v1/admin/legal-archive/documents/{$documentId}",
            sprintf('"legal-document-%d-v%d"', $documentId, $currentLockVersion),
        );
    }

    public static function forProfile(string $profileId, int $currentLockVersion): self
    {
        return new self(
            $currentLockVersion,
            'document_type_profile',
            $profileId,
            null,
            "/api/v1/admin/legal-archive/type-profiles/{$profileId}",
            sprintf('"legal-profile-%s-v%d"', $profileId, $currentLockVersion),
        );
    }

    public static function forWorkflowStep(int $stepId, int $currentLockVersion, int $documentId): self
    {
        return new self(
            $currentLockVersion,
            'legal_workflow_step',
            (string) $stepId,
            $documentId,
            "/api/v1/admin/legal-archive/documents/{$documentId}/available-actions",
            sprintf('"legal-workflow-step-%d-v%d"', $stepId, $currentLockVersion),
        );
    }

    public static function forWorkflowInstance(int $instanceId, int $currentLockVersion, int $documentId): self
    {
        return new self(
            $currentLockVersion,
            'legal_workflow_instance',
            (string) $instanceId,
            $documentId,
            "/api/v1/admin/legal-archive/documents/{$documentId}/available-actions",
            sprintf('"legal-workflow-instance-%d-v%d"', $instanceId, $currentLockVersion),
        );
    }
}
