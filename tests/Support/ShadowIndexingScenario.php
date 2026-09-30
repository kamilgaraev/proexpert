<?php

declare(strict_types=1);

namespace Tests\Support;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexRagSourceJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateItemResource;
use App\Models\EstimateSection;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use RuntimeException;

final class ShadowIndexingScenario
{
    private array $checks = [];
    private array $runIds = [];
    private array $managedRunIds = [];
    private RagIndexer $indexer;
    private RagIndexingCoordinator $coordinator;
    private RagSourceRegistry $registry;

    public function execute(Organization $organization, Project $project, string $flow, array $input): array
    {
        if (! Queue::getFacadeRoot() instanceof QueueFake || (int) $project->organization_id !== (int) $organization->id) {
            throw new RuntimeException('shadow_indexing_fixture_scope_or_queue_invalid');
        }
        $this->checks = [];
        $this->runIds = [];
        $this->managedRunIds = [];
        $input = is_array($input['input'] ?? null) ? $input['input'] : $input;
        $this->registry = app(RagSourceRegistry::class);
        $this->indexer = new RagIndexer(app(RagEmbeddingProviderInterface::class), $this->registry);
        $this->coordinator = new RagIndexingCoordinator($this->indexer);
        $variant = preg_match('/^index-([123])-[1-4]$/D', $flow, $match) ? $match[1] : $flow;
        $marker = 'shadow_index_'.substr(hash('sha256', (string) ($input['request_id'] ?? $flow)), 0, 16);
        $references = match ($variant) {
            '1', 'update_delete_reuse' => $this->estimateFlow($organization, $project, $marker),
            '2', 'coverage_lag' => $this->coverageFlow($organization, $marker),
            '3', 'lease_recovery' => $this->recoveryFlow($organization, $marker),
            default => throw new RuntimeException('shadow_indexing_flow_unsupported'),
        };
        $oracle = [];
        foreach ($references as $reference) {
            $source = $this->source($organization->id, $reference['entity_type'], $reference['entity_id']);
            $oracle[] = $reference + ['source' => $source?->only(['id', 'organization_id', 'project_id', 'entity_type', 'entity_id', 'checksum', 'source_version']),
                'chunks' => $source === null ? [] : DB::table('ai_rag_chunks')->where('source_id', $source->id)->orderBy('id')
                    ->select(['id', 'source_id', 'organization_id', 'project_id', 'content_hash', 'embedding_provider', 'embedding_model'])
                    ->selectRaw('embedding IS NOT NULL AS vector_present')->get()->map(static fn (object $row): array => (array) $row)->all()];
        }
        $requestProjectId = $references[0]['entity_type'] === 'project' ? $references[0]['entity_id'] : $project->id;
        return ['flow' => $flow, 'fixture_refs' => $references, 'request_context' => ['project_id' => $requestProjectId], 'checks' => $this->checks,
            'run_ids' => array_values(array_unique($this->runIds)), 'source_oracle' => $oracle,
            'evidence_sha256' => hash('sha256', json_encode([$this->checks, $oracle, $this->runIds], JSON_THROW_ON_ERROR))];
    }

    private function estimateFlow(Organization $organization, Project $project, string $marker): array
    {
        [$estimate, $section, $first, $second, $resource] = $this->transaction(function () use ($organization, $project, $marker): array {
            $estimate = Estimate::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
                'parent_estimate_id' => null, 'number' => $marker, 'name' => $marker, 'type' => 'local', 'status' => 'draft', 'estimate_date' => now()->toDateString()]);
            $section = EstimateSection::query()->create(['estimate_id' => $estimate->id, 'section_number' => '1', 'name' => $marker]);
            $first = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'estimate_section_id' => $section->id,
                'position_number' => '1', 'name' => $marker.'_old', 'item_type' => 'work', 'quantity' => 2, 'total_amount' => '1250.50']);
            $second = EstimateItem::query()->create(['estimate_id' => $estimate->id, 'estimate_section_id' => $section->id,
                'position_number' => '2', 'name' => $marker.'_live', 'item_type' => 'work', 'quantity' => 3, 'total_amount' => '8700.00']);
            $resource = EstimateItemResource::query()->create(['estimate_item_id' => $second->id, 'name' => $marker.'_resource', 'resource_type' => 'material', 'total_quantity' => 2]);
            return [$estimate, $section, $first, $second, $resource];
        });
        $source = $this->source($organization->id, 'estimate_item', $first->id);
        $this->check('item_created', true, $source !== null && $source->chunks()->whereNotNull('embedding')->exists());
        $oldChecksum = $source?->checksum;
        $this->transaction(fn (): bool => $first->update(['name' => $marker.'_new', 'quantity' => 4, 'total_amount' => '2501.00']));
        $changed = $this->source($organization->id, 'estimate_item', $first->id);
        $this->check('changed_checksum', true, $changed !== null && $changed->checksum !== $oldChecksum);
        $this->check('updated_content', true, $changed !== null && str_contains($changed->chunks()->firstOrFail()->content, $marker.'_new'));
        $this->checkCurrent($organization->id, 'estimate', 'estimate_item', $first->id);
        $chunkIds = $changed?->chunks()->orderBy('id')->pluck('id')->all();
        $run = $this->coordinator->queueEntity($organization->id, $project->id, 'estimate', 'estimate_item', $first->id);
        $this->managedRunIds[] = $run->id;
        $this->drain();
        $reused = $this->source($organization->id, 'estimate_item', $first->id);
        $this->check('unchanged_identity_reused', $changed?->id, $reused?->id);
        $this->check('unchanged_checksum_reused', $changed?->checksum, $reused?->checksum);
        $this->check('unchanged_chunks_reused', $chunkIds, $reused?->chunks()->orderBy('id')->pluck('id')->all());
        $this->transaction(fn (): ?bool => $second->delete());
        $this->check('deleted_item_pruned', null, $this->source($organization->id, 'estimate_item', $second->id)?->id);
        $this->check('deleted_child_resource_pruned', null, $this->source($organization->id, 'estimate_item_resource', $resource->id)?->id);
        $this->check('updated_item_preserved', true, $this->source($organization->id, 'estimate_item', $first->id)?->chunks()->whereNotNull('embedding')->exists() ?? false);
        $this->checkCurrent($organization->id, 'estimate', 'estimate_item', $first->id);
        return [['entity_type' => 'estimate', 'entity_id' => $estimate->id], ['entity_type' => 'estimate_section', 'entity_id' => $section->id],
            ['entity_type' => 'estimate_item', 'entity_id' => $first->id], ['entity_type' => 'estimate_item', 'entity_id' => $second->id],
            ['entity_type' => 'estimate_item_resource', 'entity_id' => $resource->id]];
    }

    private function coverageFlow(Organization $organization, string $marker): array
    {
        $project = $this->transaction(fn (): Project => Project::factory()->create(['organization_id' => $organization->id, 'name' => $marker.'_old']));
        $this->transaction(fn (): bool => Project::withoutTimestamps(fn (): bool => $project->update(['name' => $marker.'_new', 'updated_at' => now()->subMinutes(6)])), false);
        $coverage = new RagCoverageService($this->registry, $this->indexer);
        $pending = $coverage->refreshCoverage($organization->id, $project->id, 'project');
        $this->check('eligible_scope_known', true, $pending['eligible_count_known']);
        $this->check('pending_scope_incomplete', false, $pending['coverage_complete']);
        $this->check('changed_source_pending', 1, $pending['pending_source_count']);
        $this->check('lag_over_five_minutes', true, $pending['lag_exceeded']);
        $this->drain();
        $current = $coverage->refreshCoverage($organization->id, $project->id, 'project');
        $this->check('reconciled_scope_complete', true, $current['coverage_complete']);
        $this->check('reconciled_scope_pending', 0, $current['pending_source_count']);
        $this->checkCurrent($organization->id, 'project', 'project', $project->id);
        return [['entity_type' => 'project', 'entity_id' => $project->id]];
    }

    private function recoveryFlow(Organization $organization, string $marker): array
    {
        $project = $this->transaction(fn (): Project => Project::factory()->create(['organization_id' => $organization->id, 'name' => $marker.'_old']));
        $control = $this->transaction(fn (): Project => Project::factory()->create(['organization_id' => $organization->id, 'name' => $marker.'_control']));
        $controlSource = $this->source($organization->id, 'project', $control->id);
        $before = $this->source($organization->id, 'project', $project->id);
        $this->transaction(fn (): bool => $project->update(['name' => $marker.'_new']), false);
        $run = RagIndexRun::query()->where('organization_id', $organization->id)->where('entity_type', 'project')
            ->where('entity_id', (string) $project->id)->where('status', RagIndexRun::STATUS_QUEUED)->sole();
        $claimed = $this->coordinator->markRunning($run->id);
        $this->check('lease_claimed', true, $claimed !== null);
        $oldToken = $claimed?->lease_token;
        $claimed?->update(['lease_expires_at' => now()->subSecond()]);
        $deliveriesBefore = count(array_filter($this->jobs(), static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id));
        $this->coordinator->recoverExpiredRuns();
        $deliveriesAfter = count(array_filter($this->jobs(), static fn (IndexRagSourceJob $job): bool => $job->runId === $run->id));
        $this->check('recovery_delivery', $deliveriesBefore + 1, $deliveriesAfter);
        $this->check('expired_run_requeued', RagIndexRun::STATUS_QUEUED, $run->fresh()->status);
        $this->check('old_token_heartbeat_denied', false, $this->coordinator->heartbeat($run->id, $oldToken));
        $this->check('old_token_completion_denied', null, $this->coordinator->markSucceeded($run->id, 0, $oldToken));
        $this->check('source_preserved_during_recovery', $before?->id, $this->source($organization->id, 'project', $project->id)?->id);
        $this->drain();
        $this->check('recovered_run_succeeded', RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
        $this->check('source_identity_preserved', $before?->id, $this->source($organization->id, 'project', $project->id)?->id);
        $this->check('unrelated_source_preserved', $controlSource?->checksum, $this->source($organization->id, 'project', $control->id)?->checksum);
        $this->checkCurrent($organization->id, 'project', 'project', $project->id);
        $this->checkCurrent($organization->id, 'project', 'project', $control->id);
        return [['entity_type' => 'project', 'entity_id' => $project->id], ['entity_type' => 'project', 'entity_id' => $control->id]];
    }

    private function transaction(callable $mutation, bool $drain = true): mixed
    {
        $count = count($this->jobs());
        $result = DB::transaction(function () use ($mutation, $count): mixed {
            $result = $mutation();
            $this->check('after_commit_dispatch_'.count($this->checks), $count, count($this->jobs()));
            return $result;
        });
        $this->check('observer_dispatched_'.count($this->checks), true, count($this->jobs()) > $count);
        foreach (array_slice($this->jobs(), $count) as $job) {
            if ($job instanceof IndexRagSourceJob && $job->runId !== null) { $this->managedRunIds[] = $job->runId; }
        }
        if ($drain) { $this->drain(); }
        return $result;
    }

    private function jobs(): array
    {
        $queue = Queue::getFacadeRoot();
        if (! $queue instanceof QueueFake) { throw new RuntimeException('shadow_indexing_queue_not_observable'); }
        return $queue->pushed(IndexRagSourceJob::class)->values()->all();
    }

    private function drain(): void
    {
        foreach (array_reverse($this->jobs()) as $job) {
            if (! $job instanceof IndexRagSourceJob || $job->runId === null || ! in_array($job->runId, $this->managedRunIds, true)
                || in_array($job->runId, $this->runIds, true)) { continue; }
            $run = RagIndexRun::query()->find($job->runId);
            if ($run?->status !== RagIndexRun::STATUS_QUEUED) { continue; }
            $job->handle($this->indexer, $this->coordinator);
            $this->check('run_succeeded_'.$run->id, RagIndexRun::STATUS_SUCCEEDED, $run->fresh()->status);
            $this->runIds[] = $run->id;
        }
    }

    private function source(int $organizationId, string $entityType, int|string $entityId): ?RagSource
    {
        return RagSource::query()->where('organization_id', $organizationId)->where('entity_type', $entityType)->where('entity_id', (string) $entityId)->first();
    }

    private function checkCurrent(int $organizationId, string $sourceType, string $entityType, int|string $entityId): void
    {
        $chunks = $this->registry->collector($sourceType)?->collectEntity($organizationId, $entityType, $entityId) ?? [];
        $source = $this->source($organizationId, $entityType, $entityId);
        $matched = false;
        foreach ($chunks as $chunk) { $matched = $source !== null && $this->indexer->matchesSource($source, $chunk); }
        $this->check('current_checksum_'.$entityType.'_'.$entityId, true, $matched);
    }

    private function check(string $name, mixed $expected, mixed $observed): void
    {
        $passed = $expected === $observed;
        $this->checks[$name] = ['expected' => $expected, 'observed' => $observed, 'passed' => $passed];
        if (! $passed) { throw new RuntimeException('shadow_indexing_oracle_failed:'.$name); }
    }
}
