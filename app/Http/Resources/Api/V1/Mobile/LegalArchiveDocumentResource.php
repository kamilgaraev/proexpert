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
            'project' => $this->whenLoaded('project', fn (): ?array => $this->project === null ? null : [
                'id' => (int) $this->project->id,
                'name' => (string) $this->project->name,
            ]),
            'counterparty_name' => $this->counterparty_name,
            'current_version' => $this->version($this->whenLoaded('currentVersion')),
            'versions' => $this->whenLoaded('versions', fn (): array => $this->versions->map(fn ($version): array => $this->version($version))->all()),
            'signature_summary' => ['status' => ((int) ($this->signatures_count ?? 0)) > 0 ? 'registered' : 'not_signed'],
            'workflow_summary' => $this->workflowSummary,
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
            'created_at' => $version->created_at?->toISOString(),
        ];
    }
}
