<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetEvidence;

final readonly class EloquentTargetedSheetEvidenceResolver implements TargetedSheetEvidenceResolver
{
    public function __construct(private BoundedVersionedS3ObjectReader $reader) {}

    public function resolvePeer(DocumentUnitExecutionContext $context, string $role): ?TargetedSheetEvidence
    {
        $page = EstimateGenerationDocumentPage::query()
            ->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)
            ->where('session_id', $context->sessionId)
            ->where('id', '<>', $context->pageId)
            ->where('status', 'completed')
            ->where('normalized_payload->sheet_analysis_routing->role', $role)
            ->orderBy('id')
            ->first();
        if (! $page instanceof EstimateGenerationDocumentPage) {
            return null;
        }
        $payload = is_array($page->normalized_payload) ? $page->normalized_payload : [];
        $preprocessing = is_array($payload['preprocessing'] ?? null) ? $payload['preprocessing'] : [];
        $storageKey = $preprocessing['derivative_storage_key'] ?? null;
        $bytes = $preprocessing['derivative_bytes'] ?? null;
        $hash = $preprocessing['derivative_hash'] ?? null;
        if (! is_string($storageKey) || ! is_int($bytes) || ! is_string($hash)
            || ! is_string($page->source_version) || ! is_int($page->processing_unit_id)) {
            return null;
        }
        $image = $this->reader->read(
            $context->organizationId,
            $storageKey,
            max(1, (int) config('estimate-generation.vision.preprocessing.max_bytes', 20_000_000)),
            $bytes,
            $hash,
        )->body;

        return new TargetedSheetEvidence(
            $context->organizationId,
            $context->projectId,
            $context->sessionId,
            (int) $page->document_id,
            (int) $page->getKey(),
            (int) $page->page_number,
            (int) $page->processing_unit_id,
            $page->source_version,
            $hash,
            'image/png',
            $image,
        );
    }
}
