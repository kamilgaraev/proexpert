<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\User;
use Illuminate\Support\Carbon;

class AssistantSourceReferenceGuard
{
    private array $columns = [];

    public function __construct(private readonly AssistantDataAccessPolicy $policy,
        private readonly ?\App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection $projections = null) {}

    public function canRead(User $actor, int $organizationId, array $references, bool $fresh = true): bool
    {
        return $this->policy->withCurrentChecks($actor, $organizationId,
            fn (): bool => $this->policy->canReadReferences($actor, $organizationId, $references), $fresh);
    }

    private function canReadCurrent(User $actor, int $organizationId, array $references): bool
    {
        foreach ($references as $reference) {
            if (! is_array($reference) || (isset($reference['organization_id']) && (int) $reference['organization_id'] !== $organizationId)) {
                return false;
            }
            if (! $this->policy->canReadReference($actor, $organizationId, $reference)) {
                return false;
            }
        }

        return true;
    }

    public function fresh(User $actor, int $organizationId, array $references): bool
    {
        return $this->policy->withCurrentChecks($actor, $organizationId,
            fn (): bool => $this->freshCurrent($actor, $organizationId, $references), true);
    }

    private function freshCurrent(User $actor, int $organizationId, array $references): bool
    {
        if (! $this->canReadCurrent($actor, $organizationId, $references)) {
            return false;
        }
        $currentSourceMatches = [];
        foreach ($references as $reference) {
            $fetchedAt = $reference['fetched_at'] ?? null;
            if (! is_string($fetchedAt)) {
                return false;
            }
            try {
                $timestamp = Carbon::parse($fetchedAt);
            } catch (\Throwable) {
                return false;
            }
            if ($timestamp->isFuture() || $timestamp->lt(now()->subMinutes(5))) {
                return false;
            }
            $type = $reference['entity_type'] ?? $reference['entityType'] ?? $reference['type'];
            $id = $reference['entity_id'] ?? $reference['entityId'] ?? $reference['id'];
            if ($type === AssistantDomainSearchCountEvidence::TYPE) {
                if (! app(AssistantDomainSearchCountEvidence::class)->matchesReference($actor, $organizationId, $reference)) {
                    return false;
                }
                continue;
            }
            if ($type === 'live_project_financial_projection') {
                if (! app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader::class)
                    ->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectMaterialStockReader::TYPE) {
                if (! app(AssistantProjectMaterialStockReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectAttentionReader::TYPE) {
                if (! app(AssistantProjectAttentionReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectScheduleImpactReader::TYPE) {
                if (! app(AssistantProjectScheduleImpactReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectWorkforceReader::TYPE) {
                if (! app(AssistantProjectWorkforceReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectPaymentReader::TYPE) {
                if (! app(AssistantProjectPaymentReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectHandoverReader::TYPE) {
                if (! app(AssistantProjectHandoverReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectMachineryReader::TYPE) {
                if (! app(AssistantProjectMachineryReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === AssistantProjectContractRosterReader::TYPE) {
                if (! app(AssistantProjectContractRosterReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === \App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader::TYPE) {
                if (! app(\App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentReadinessReader::class)->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === 'published_report_financial_projection') {
                if (! app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportReader::class)
                    ->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if (isset($reference['estimate_projection'])) {
                if (! app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantEstimatePositionReadService::class)
                    ->matchesProjectedReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if (isset($reference['projection_name'])) {
                if (! ($this->projections ?? app(\App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection::class))
                    ->fresh($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            $engineeringReference = ($reference['bim_projection'] ?? null) === 'engineering_index';
            $query = $type === 'assistant_document' ? AIAssistantDocument::query()->where('organization_id', $organizationId)
                : ($engineeringReference ? $this->policy->engineeringEntityQuery($actor, $organizationId, $type)
                    : $this->policy->entityQuery($actor, $organizationId, $type));
            if ($type === 'machinery_asset' && isset($reference['machinery_project_scope'])) {
                if ($query === null || ! is_int($reference['machinery_project_scope']) || $reference['machinery_project_scope'] < 1
                    || ($reference['project_id'] ?? null) !== $reference['machinery_project_scope']) { return false; }
                try { app(AssistantMachineryProjectScope::class)->apply($query, $actor, $organizationId, $reference['machinery_project_scope']); }
                catch (\Throwable) { return false; }
            }
            $allowed = [];
            $relatedFields = [];
            if (isset($reference['related_field_projection'])) {
                if ($reference['related_field_projection'] !== AssistantDomainRelatedFields::PROJECTION
                    || AssistantDomainRelatedFields::fields($type) === []) { return false; }
                $relatedFields = AssistantDomainRelatedFields::fields($type);
            }
            if ($engineeringReference && $query !== null) {
                $allowed = array_map(static fn (string $column): string => substr($column, strrpos($column, '.') + 1), $query->getQuery()->columns ?? []);
                if (! is_array($reference['checked_fields'] ?? null) || array_diff($reference['checked_fields'], $allowed) !== []) { return false; }
            }
            if ($query !== null && ($reference['content_scope'] ?? null) === 'structured') {
                $table = $query->getModel()->getTable();
                $columns = $this->columns[$table] ??= \Illuminate\Support\Facades\Schema::getColumnListing($table);
                $internalColumns = array_intersect([...($type === 'schedule_task' ? ['schedule_id'] : []),
                    ...($relatedFields === [] ? [] : [AssistantDomainRelatedFields::parentColumn($type)])], $columns);
                $safeColumns = $engineeringReference ? $allowed : (AssistantExtendedDomainRegistry::values('safeSelectColumns')[$type] ?? $columns);
                $columns = array_values(array_intersect($columns, $safeColumns));
                if (array_diff($reference['checked_fields'] ?? [], [...$columns, ...$relatedFields]) !== []) { return false; }
                $selected = array_values(array_unique([...$internalColumns, ...array_intersect([$query->getModel()->getKeyName(), 'updated_at', ...($reference['checked_fields'] ?? [])], $columns)]));
                $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $selected));
            }
            $entity = $query?->whereKey($id)->first();
            if (! $entity) {
                return false;
            }
            if ($relatedFields !== []) { AssistantDomainRelatedFields::hydrate([$entity], $type, $organizationId); }
            if ($type === 'schedule_task' && array_key_exists('project_id', $reference) && ($reference['content_scope'] ?? null) === 'structured'
                && $reference['project_id'] !== $this->policy->resolvedEntityProjectId($actor, $organizationId, $type, $entity)) {
                return false;
            }
            $unit = null;
            if ($type === 'assistant_document') {
                if (isset($reference['document_id']) && (int) $reference['document_id'] !== (int) $entity->id) {
                    return false;
                }
                $documentChecksum = $reference['document_checksum'] ?? (! isset($reference['source_id']) ? ($reference['checksum'] ?? null) : null);
                if ($documentChecksum !== null && (! is_string($documentChecksum)
                    || ! hash_equals((string) $entity->checksum, $documentChecksum))) {
                    return false;
                }
                if (isset($reference['unit_id'])) {
                    $unitColumns = ['id', 'document_id', 'unit_type', 'unit_index', 'provenance'];
                    if (isset($reference['source_id'])) { array_push($unitColumns, 'text', 'checksum'); }
                    $unit = \App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit::query()
                        ->where('document_id', $entity->id)->whereKey($reference['unit_id'])
                        ->whereRaw('char_length(text) <= ?', [2000000])->select($unitColumns)
                        ->selectRaw("encode(sha256(convert_to(text, 'UTF8')), 'hex') AS current_text_checksum")->first();
                    if ($unit === null || ! is_string($reference['unit_checksum'] ?? null)
                        || ! hash_equals((string) $unit->current_text_checksum, $reference['unit_checksum'])
                        || (isset($reference['unit_index']) && (int) $reference['unit_index'] !== (int) $unit->unit_index)
                        || (isset($reference['unit_type']) && $reference['unit_type'] !== $unit->unit_type)) {
                        return false;
                    }
                    if (isset($reference['provenance'])) {
                        $expected = \App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder::documentEvidence(['provenance' => $unit->provenance]);
                        if (($expected['provenance'] ?? []) !== $reference['provenance']) {
                            return false;
                        }
                    }
                }
            }
            if (array_key_exists('source_field_identity', $reference)) {
                $fields = $reference['checked_fields'] ?? null;
                if (! is_array($fields) || $fields === [] || ! is_string($reference['source_field_identity'])) {
                    return false;
                }
                $identity = AssistantSourceReferenceIdentity::key(array_intersect_key($entity->getAttributes(), array_flip($fields)));
                if (! hash_equals($identity, $reference['source_field_identity'])) {
                    return false;
                }
            }
            if ($type === 'design_source_link' && array_key_exists('source_link_identity', $reference)) {
                $identity = AssistantSourceReferenceIdentity::key(array_intersect_key($entity->getAttributes(),
                    array_flip($reference['checked_fields'] ?? [])));
                if (! is_string($reference['source_link_identity']) || ! hash_equals($identity, $reference['source_link_identity'])) {
                    return false;
                }
            }
            if (! $entity->updated_at) {
                $fields = $reference['checked_fields'] ?? null;
                if (($reference['content_scope'] ?? null) === 'structured') {
                    if (! is_array($fields) || $fields === []) { return false; }
                    $version = AssistantSourceReferenceIdentity::key(array_intersect_key($entity->getAttributes(), array_flip($fields)));
                    if (! is_string($reference['source_version'] ?? null) || ! hash_equals($version, $reference['source_version'])) { return false; }
                } elseif (! isset($reference['source_id'])) { return false; }
            } elseif (Carbon::parse($entity->updated_at)->gt($timestamp)) {
                return false;
            }
            if (isset($reference['source_id'])) {
                $source = RagSource::query()->where('organization_id', $organizationId)->find($reference['source_id']);
                $sourceChecksum = $reference['rag_source_checksum'] ?? $reference['checksum'] ?? null;
                if (! $source || ! is_string($sourceChecksum) || ! hash_equals((string) $source->checksum, $sourceChecksum)) {
                    return false;
                }
                $proofKey = $source->id.':'.$source->checksum.':'.$timestamp->toISOString();
                if (! array_key_exists($proofKey, $currentSourceMatches)) {
                    try {
                        $collector = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry::class)->collector($source->source_type);
                        $current = false;
                        $chunks = $type === 'assistant_document' && $unit !== null && $source->source_type === 'file_document'
                            ? [\App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource::chunkForUnit($entity, $unit)]
                            : ($collector?->collectEntity($organizationId, $type, $id) ?? []);
                        foreach ($chunks as $chunk) {
                            if ($chunk->entityType === $type && (string) $chunk->entityId === (string) $id
                                && ($chunk->updatedAt === null || Carbon::parse($chunk->updatedAt)->lte($timestamp))
                                && app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer::class)->matchesLexicalSource($source, $chunk)) { $current = true; break; }
                        }
                    } catch (\Throwable) {
                        return false;
                    }
                    $currentSourceMatches[$proofKey] = $current;
                }
                if (! $currentSourceMatches[$proofKey]) { return false; }
            }
        }

        return true;
    }
}
