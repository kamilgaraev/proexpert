<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Mobile;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\LegalArchiveDictionary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LegalArchiveDocumentResource extends JsonResource
{
    /** @param array<string, mixed> $workflowSummary */
    public function __construct(LegalArchiveDocument $resource, private readonly array $workflowSummary = [])
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'document_number' => $this->document_number,
            'document_type' => (string) $this->document_type,
            'document_type_label' => LegalArchiveDictionary::label('types', (string) $this->document_type),
            'status' => (string) $this->status,
            'status_label' => LegalArchiveDictionary::label('statuses', (string) $this->status),
            'lock_version' => (int) $this->lock_version,
            'project' => $this->whenLoaded('project', fn (): ?array => $this->project === null ? null : [
                'id' => (int) $this->project->id,
                'name' => (string) $this->project->name,
            ]),
            'counterparty_name' => $this->counterparty_name,
            'current_version' => $this->version($this->whenLoaded('currentVersion')),
            'versions' => $this->whenLoaded('versions', fn (): array => $this->versions->map(fn ($version): array => $this->version($version))->all()),
            'signature_summary' => ['status' => ((int) ($this->signatures_count ?? 0)) > 0 ? 'registered' : 'not_signed'],
            'signature_requests' => $this->whenLoaded('signatureRequests', fn (): array => $this->signatureRequests
                ->where('status', 'pending')
                ->map(fn ($request): array => ['id' => (int) $request->id, 'method' => (string) $request->method])
                ->values()->all()),
            'workflow_summary' => $this->workflowSummary,
            'obligations' => $this->whenLoaded('obligations', fn (): array => $this->obligations->map(fn ($obligation): array => ['id' => (int) $obligation->id, 'title' => (string) $obligation->title, 'status' => (string) $obligation->status, 'due_at' => $obligation->due_at?->toISOString()])->all()),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed>|null */
    private function version(mixed $version): ?array
    {
        if ($version === null || $version instanceof \Illuminate\Http\Resources\MissingValue) {
            return null;
        }

        return [
            'id' => (int) $version->id,
            'version_number' => (int) $version->version_number,
            'file_name' => $version->original_filename,
            'content_hash' => $version->content_hash,
            'mime_type' => $version->mime_type,
            'size_bytes' => $version->size_bytes === null ? null : (int) $version->size_bytes,
            'processing_status' => (string) $version->processing_status,
            'preview_available' => $version->processing_status === 'ready' && in_array((string) $version->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true),
            'created_at' => $version->created_at?->toISOString(),
        ];
    }
}
