<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\Sources;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;

final class FileRagSource implements RagSourceCollectorInterface
{
    public function sourceType(): string { return 'file_document'; }
    public function enabled(): bool { return true; }
    public function collectForOrganization(int $organizationId, ?int $projectId = null): iterable
    {
        foreach (AIAssistantDocument::query()->where('organization_id', $organizationId)->where('status', AIAssistantDocument::STATUS_READY)->when($projectId !== null, static fn ($query) => $query->where('project_id', $projectId))->lazyById(50) as $document) yield from $this->units($document);
    }
    public function collectEntity(int $organizationId, string $entityType, string|int $entityId): iterable
    {
        if ($entityType !== 'assistant_document') return [];
        $document = AIAssistantDocument::query()->where('organization_id', $organizationId)->whereKey($entityId)->where('status', AIAssistantDocument::STATUS_READY)->first();
        return $document instanceof AIAssistantDocument ? $this->units($document) : [];
    }
    private function units(AIAssistantDocument $document): iterable
    {
        foreach (AIAssistantDocumentUnit::query()->where('document_id', $document->id)->orderBy('unit_index')->cursor() as $unit) if (trim($unit->text) !== '') yield new RagChunkData($document->organization_id, $document->project_id, $this->sourceType(), 'assistant_document', (string) $document->id, $document->filename, $unit->text, ['document_id' => $document->id, 'file_id' => $document->file_id, 'unit_id' => $unit->id, 'parent_entity_type' => $document->parent_entity_type, 'parent_entity_id' => $document->parent_entity_id, 'provenance' => $unit->provenance, 'coverage_status' => $document->coverage_status, 'checksum' => $document->checksum], $document->processed_at);
    }
}
