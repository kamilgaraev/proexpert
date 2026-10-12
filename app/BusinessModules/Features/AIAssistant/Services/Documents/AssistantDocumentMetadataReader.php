<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Documents;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\Models\User;

final readonly class AssistantDocumentMetadataReader
{
    public function __construct(private AssistantDataAccessPolicy $policy) {}

    public function read(User $actor, int $organizationId, array $arguments, ?callable $checkpoint = null): array
    {
        $checkpoint?->__invoke();
        $query = $this->policy->accessibleDocuments($actor, $organizationId);
        if (isset($arguments['project_id'])) { $query->where('project_id', $arguments['project_id']); }
        if (isset($arguments['document_status'])) { $query->where('status', $arguments['document_status']); }
        $name = trim((string) ($arguments['filename_query'] ?? ''));
        if ($name !== '') { $query->where('filename', 'ilike', '%'.addcslashes($name, '%_\\').'%'); }
        $limit = min((int) ($arguments['limit'] ?? 20), AssistantStructuredFactFormatter::MAX_ROWS);
        $offset = (int) ($arguments['metadata_offset'] ?? 0);
        $fields = ['id', 'filename', 'status', 'coverage_status', 'project_id', 'file_id'];
        $documents = $query->select([...$fields, 'updated_at'])->orderBy('id')->offset($offset)->limit($limit + 1)->get();
        $fetchedAt = now()->toISOString();
        $rows = [];
        foreach ($documents->take($limit) as $document) {
            $checkpoint?->__invoke();
            $reference = ['source_type' => 'file_document', 'entity_type' => 'assistant_document', 'entity_id' => (int) $document->id,
                'organization_id' => $organizationId, 'project_id' => $document->project_id, 'content_scope' => 'structured',
                'checked_fields' => $fields, 'required_permissions' => ['ai_assistant.chat'], 'required_domains' => ['assistant'],
                'source_version' => (string) $document->getRawOriginal('updated_at'), 'fetched_at' => $fetchedAt,
                'document_metadata_id_requested' => ($arguments['include_document_id'] ?? false) === true,
                'source_field_identity' => AssistantSourceReferenceIdentity::key(array_intersect_key($document->getAttributes(), array_flip($fields)))];
            $row = AssistantStructuredFactFormatter::row($document, 'assistant_document', $fields, $reference);
            if ($row !== null) { $rows[] = $row; }
        }
        return ['status' => 'success', 'read_scope' => 'document_metadata', 'search_scope' => 'document_metadata',
            'source_refs' => array_column($rows, 'source_ref'), 'fetched_at' => $fetchedAt,
            'result_window' => ['offset' => $offset, 'limit' => $limit, 'returned' => count($rows), 'has_more' => $documents->count() > $limit,
                'next_offset' => $documents->count() > $limit ? $offset + $limit : null]]
            + AssistantStructuredFactFormatter::payload($rows, $fetchedAt);
    }
}
