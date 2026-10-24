<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocumentUnit;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceGuard;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCorpus;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\FileRagSource;
use App\Models\File;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDocumentCorpusCoverageQueryTest extends TestCase
{
    private AssistantRealAuthorizationFixture $fixture;
    private AIAssistantDocument $document;
    private AIAssistantDocumentUnit $unit;
    private RagChunk $chunk;
    private AssistantDocumentCorpus $corpus;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 10:00:00');
        Queue::fake();
        config(['cache.default' => 'array']);
        $this->fixture = Model::withoutEvents(fn () => AssistantRealAuthorizationFixture::create(
            array_column(app(PackageCatalogService::class)->allPackages(), 'slug')));
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $file = Model::withoutEvents(fn () => File::query()->create(['organization_id' => $this->fixture->organization->id,
            'user_id' => $this->fixture->owner->id, 'fileable_type' => $project->getMorphClass(), 'fileable_id' => $project->id,
            'name' => 'Coverage fixture.txt', 'original_name' => 'Coverage fixture.txt', 'path' => 'private/coverage.txt',
            'disk' => 's3', 'mime_type' => 'text/plain', 'size' => 100]));
        $this->document = Model::withoutEvents(fn () => AIAssistantDocument::query()->create([
            'organization_id' => $this->fixture->organization->id, 'project_id' => $project->id, 'file_id' => $file->id,
            'parent_entity_type' => 'project', 'parent_entity_id' => (string) $project->id, 'storage_path' => $file->path,
            'filename' => $file->name, 'mime_type' => 'text/plain', 'checksum' => hash('sha256', 'coverage-document'),
            'size_bytes' => 100, 'status' => 'ready', 'coverage_status' => 'full', 'processed_at' => now(),
            'extracted_text' => 'Synthetic coverage text', 'metadata' => []]));
        $this->unit = AIAssistantDocumentUnit::query()->create(['document_id' => $this->document->id,
            'unit_type' => 'page', 'unit_index' => 0, 'text' => 'Synthetic coverage text',
            'checksum' => hash('sha256', 'Synthetic coverage text'), 'provenance' => ['page' => 1]]);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->expects(self::never())->method('embed');
        $this->app->instance(RagEmbeddingProviderInterface::class, $provider);
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $data = FileRagSource::chunkForUnit($this->document, $this->unit);
        $source = RagSource::query()->create($indexer->coverageIdentity($data) + ['title' => $data->title,
            'source_version' => now()->toAtomString(), 'metadata' => $data->metadata, 'indexed_at' => now()]);
        $this->chunk = RagChunk::query()->create(['source_id' => $source->id, 'organization_id' => $data->organizationId,
            'project_id' => $data->projectId, 'chunk_index' => 0, 'content' => $this->unit->text,
            'content_hash' => $this->unit->checksum, 'metadata' => $data->metadata + ['chunk_index' => 0, 'chunk_count' => 1]]);
        $this->actingAs($this->fixture->owner);
        $this->corpus = AssistantDocumentCorpus::capture($this->fixture->owner, $this->fixture->organization->id,
            $project->id, $this->document->id, [$this->chunk->id], app(AssistantDataAccessPolicy::class),
            app(AssistantSourceReferenceGuard::class));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_corpus_coverage_uses_one_document_visibility_statement(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $event) use (&$queries): void {
            $queries[] = strtolower($event->sql);
        });
        $coverage = $this->coverage();
        self::assertSame($this->expected(true, 1, 1, 0), $coverage);
        $aggregates = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'assistant_coverage_documents')
            && str_contains($sql, 'expected_units') && str_contains($sql, 'current_units') && str_contains($sql, 'partial_documents'));
        self::assertCount(1, $aggregates);
        $visibility = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'ai_assistant_documents')
            && preg_match('/^\s*select\s+exists\s*\(/', $sql) === 1);
        self::assertCount(0, $visibility, 'Document presence must share the existing coverage aggregate, without a separate ACL EXISTS.');
    }

    public function test_visible_document_without_units_is_present_but_incomplete(): void
    {
        $this->unit->delete();
        self::assertSame($this->expected(false, 0, 0, 1), $this->coverage());
    }

    public function test_deleted_document_is_incomplete_even_when_zero_units_match(): void
    {
        $this->document->delete();
        self::assertSame($this->expected(false, 0, 0, 0), $this->coverage());
    }

    public function test_foreign_project_target_is_not_replaced_by_another_visible_document(): void
    {
        $project = Project::withoutEvents(fn () => Project::factory()->create([
            'organization_id' => $this->fixture->organization->id, 'is_archived' => false]));
        $other = $this->document->replicate();
        $other->checksum = hash('sha256', 'another-visible-document');
        $other->saveQuietly();
        $this->document->updateQuietly(['project_id' => $project->id]);
        self::assertSame($this->expected(false, 0, 0, 0), $this->coverage());
    }

    public function test_membership_revocation_is_rechecked_after_successful_capture(): void
    {
        self::assertTrue($this->coverage()['complete']);
        $this->fixture->organization->users()->updateExistingPivot($this->fixture->owner->id, ['is_active' => false]);
        self::assertSame($this->expected(false, 0, 0, 0), $this->coverage());
    }

    public function test_changed_raw_unit_still_fails_current_index_proof(): void
    {
        self::assertTrue($this->coverage()['complete']);
        $this->unit->update(['text' => 'Changed raw unit without new checksum']);
        self::assertSame($this->expected(false, 1, 0, 0), $this->coverage());
    }

    public function test_missing_chunk_still_fails_current_index_proof(): void
    {
        $this->chunk->delete();
        self::assertSame($this->expected(false, 1, 0, 0), $this->coverage());
    }

    private function coverage(): array
    {
        return app(AssistantDocumentCoverageService::class)->coverageForCorpus(
            $this->corpus, $this->fixture->owner, $this->fixture->organization->id);
    }

    private function expected(bool $complete, int $expected, int $current, int $partial): array
    {
        return ['coverage_scope' => 'approved_fragment_universe', 'document_ids' => [$this->document->id],
            'approved_fragments' => 1, 'complete' => $complete,
            'retrieval_coverage' => ['complete' => $partial === 0 && $expected === $current, 'fresh' => true,
                'mode' => 'lexical', 'expected_units' => $expected, 'current_units' => $current, 'partial_documents' => $partial]];
    }
}