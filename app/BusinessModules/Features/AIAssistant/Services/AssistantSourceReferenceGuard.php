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
            if ($type === 'live_project_financial_projection') {
                if (! app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantLiveProjectFinanceReader::class)
                    ->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if ($type === 'published_report_financial_projection') {
                if (! app(\App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\AssistantPublishedReportReader::class)
                    ->matchesReference($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            if (isset($reference['projection_name'])) {
                if (! ($this->projections ?? app(\App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection::class))
                    ->fresh($actor, $organizationId, $reference)) { return false; }
                continue;
            }
            $query = $type === 'assistant_document' ? AIAssistantDocument::query()->where('organization_id', $organizationId)
                : $this->policy->entityQuery($actor, $organizationId, $type);
            if ($query !== null && ($reference['content_scope'] ?? null) === 'structured') {
                $table = $query->getModel()->getTable();
                $columns = $this->columns[$table] ??= \Illuminate\Support\Facades\Schema::getColumnListing($table);
                $selected = array_values(array_intersect([$query->getModel()->getKeyName(), 'updated_at', ...($reference['checked_fields'] ?? [])], $columns));
                $query->select(array_map(static fn (string $column): string => $table.'.'.$column, $selected));
            }
            $entity = $query?->whereKey($id)->first();
            if (! $entity) {
                return false;
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
                if (! $source || ! isset($reference['checksum']) || ! hash_equals((string) $source->checksum, (string) $reference['checksum'])) {
                    return false;
                }
                if (! $entity->updated_at) {
                    try {
                        $collector = app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry::class)->collector($source->source_type);
                        $current = false;
                        foreach ($collector?->collectEntity($organizationId, $type, $id) ?? [] as $chunk) {
                            if ($chunk->entityType === $type && (string) $chunk->entityId === (string) $id
                                && ($chunk->updatedAt === null || Carbon::parse($chunk->updatedAt)->lte($timestamp))
                                && app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer::class)->matchesSource($source, $chunk)) { $current = true; break; }
                        }
                    } catch (\Throwable) {
                        return false;
                    }
                    if (! $current) { return false; }
                }
            }
        }

        return true;
    }
}
