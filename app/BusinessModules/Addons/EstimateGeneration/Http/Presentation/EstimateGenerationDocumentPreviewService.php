<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;

final readonly class EstimateGenerationDocumentPreviewService
{
    private const TTL_MINUTES = 5;

    private const SAFE_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];

    public function __construct(
        private AuthorizationService $authorization,
        private FileService $files,
    ) {}

    public function forDocument(EstimateGenerationDocument $document, User $user): ?string
    {
        $session = $document->relationLoaded('session') ? $document->session : null;
        $mimeType = strtolower(trim((string) $document->mime_type));
        $path = trim((string) $document->storage_path, '/');
        if (
            ! $session instanceof EstimateGenerationSession
            || (string) $document->status === 'failed'
            || ! in_array($mimeType, self::SAFE_TYPES, true)
            || ! $this->isScopedPath($document, $session, $path)
            || (int) $user->current_organization_id !== (int) $document->organization_id
        ) {
            return null;
        }

        if (! $this->authorization->can($user, 'estimate_generation.view', [
            'organization_id' => (int) $document->organization_id,
            'project_id' => (int) $document->project_id,
        ])) {
            return null;
        }

        $organization = new Organization;
        $organization->forceFill(['id' => (int) $document->organization_id]);
        $safeFilename = rawurlencode(basename(str_replace(["\r", "\n"], '', (string) $document->filename)));

        return $this->files->temporaryUrl($path, self::TTL_MINUTES, $organization, [
            'ResponseContentType' => $mimeType,
            'ResponseContentDisposition' => "inline; filename*=UTF-8''{$safeFilename}",
        ]);
    }

    private function isScopedPath(
        EstimateGenerationDocument $document,
        EstimateGenerationSession $session,
        string $path,
    ): bool {
        $prefix = sprintf(
            'org-%d/estimate-generation/sessions/%d/documents/',
            (int) $document->organization_id,
            (int) $session->getKey(),
        );

        return (int) $session->organization_id === (int) $document->organization_id
            && (int) $session->project_id === (int) $document->project_id
            && (int) $session->getKey() === (int) $document->session_id
            && str_starts_with($path, $prefix)
            && ! str_contains($path, '../');
    }
}
