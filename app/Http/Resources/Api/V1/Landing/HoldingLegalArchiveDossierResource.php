<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Landing;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class HoldingLegalArchiveDossierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var LegalArchiveDocument $document */
        $document = $this->resource;
        $canDownload = (bool) $document->getAttribute('holding_can_download');
        $financial = $document->getAttribute('holding_financial_summary');

        return [
            'id' => (int) $document->id,
            'organization' => $document->organization === null ? null : [
                'id' => (int) $document->organization->id,
                'name' => (string) $document->organization->name,
            ],
            'contract' => $document->getAttribute('holding_contract'),
            'title' => (string) $document->title,
            'document_number' => $document->document_number,
            'document_type' => (string) $document->document_type,
            'document_type_label' => LegalArchiveDictionary::label('types', (string) $document->document_type),
            'status' => (string) $document->status,
            'status_label' => LegalArchiveDictionary::label('statuses', (string) $document->status),
            'document_date' => $document->document_date?->toDateString(),
            'effective_from' => $document->effective_from?->toDateString(),
            'effective_until' => $document->effective_until?->toDateString(),
            'counterparty_name' => $document->counterparty_name,
            'legal_significance_status' => $document->legal_significance_status,
            'legal_significance_status_label' => LegalArchiveDictionary::label(
                'legal_significance_statuses',
                (string) $document->legal_significance_status,
            ),
            'workflow_summary' => [
                'status' => 'read_only',
                'available_action_details' => [],
            ],
            'signature_summary' => [
                'total' => $document->signatures->count(),
                'verified' => $document->signatures->whereNotNull('verified_at')->count(),
                'status' => $document->signature_status,
            ],
            'financial_summary' => $financial === null ? [
                'visible' => false,
                'total_amount' => null,
                'paid_amount' => null,
                'remaining_amount' => null,
            ] : [
                'visible' => true,
                ...$financial,
            ],
            'files' => $canDownload ? $document->files->map(
                static fn (LegalArchiveDocumentFile $file): array => [
                    'id' => (int) $file->id,
                    'title' => (string) $file->title,
                    'role' => (string) $file->role,
                    'current_version' => $file->currentVersion instanceof LegalArchiveDocumentVersion ? [
                        'id' => (int) $file->currentVersion->id,
                        'version_number' => (int) $file->currentVersion->version_number,
                        'original_filename' => (string) $file->currentVersion->original_filename,
                        'mime_type' => (string) $file->currentVersion->mime_type,
                        'size_bytes' => (int) $file->currentVersion->size_bytes,
                        'preview_available' => (string) $file->currentVersion->processing_status === 'ready',
                    ] : null,
                ],
            )->values() : [],
            'permissions' => [
                'can_preview_download' => $canDownload,
                'read_only' => true,
            ],
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }
}
