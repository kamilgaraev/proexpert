<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\RagTestEmbedding;
use Tests\TestCase;

final class RagStatusReadProjectionTest extends TestCase
{
    public function test_source_projection_tracks_identity_and_schema_without_copying_private_metadata(): void
    {
        $source = $this->source();
        $row = DB::table('ai_rag_status_sources')->where('id', $source->id)->first();
        self::assertNotNull($row);
        self::assertSame($source->organization_id, $row->organization_id);
        self::assertNull($row->project_id);
        self::assertSame(['assistant_public_schema_revision' => 'revision-1'], json_decode($row->metadata, true));
        $other = Organization::factory()->create();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $other->id]));
        DB::table('ai_rag_sources')->where('id', $source->id)->update([
            'organization_id' => $other->id, 'project_id' => $project->id,
            'entity_type' => 'changed_type', 'entity_id' => '0001',
            'metadata' => json_encode(['assistant_public_schema_revision' => 'revision-2', 'private' => 'hidden']),
        ]);
        $row = DB::table('ai_rag_status_sources')->where('id', $source->id)->first();
        self::assertNotNull($row);
        self::assertSame($other->id, $row->organization_id);
        self::assertSame($project->id, $row->project_id);
        self::assertSame('changed_type', $row->entity_type);
        self::assertSame('0001', $row->entity_id);
        self::assertSame(['assistant_public_schema_revision' => 'revision-2'], json_decode($row->metadata, true));
        DB::table('ai_rag_sources')->where('id', $source->id)->delete();
        self::assertFalse(DB::table('ai_rag_status_sources')->where('id', $source->id)->exists());
    }

    public function test_chunk_projection_tracks_null_embedding_and_exact_organization_project_and_source(): void
    {
        $source = $this->source();
        $chunk = $this->chunk($source);
        $row = DB::table('ai_rag_status_chunks')->where('id', $chunk)->first();
        self::assertNotNull($row);
        self::assertFalse($row->embedding_present);
        self::assertNull($row->project_id);
        $otherSource = $this->source();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $otherSource->organization_id]));
        DB::table('ai_rag_chunks')->where('id', $chunk)->update([
            'source_id' => $otherSource->id, 'organization_id' => $otherSource->organization_id,
            'project_id' => $project->id, 'embedding' => $this->vector(1.0),
        ]);
        $row = DB::table('ai_rag_status_chunks')->where('id', $chunk)->first();
        self::assertNotNull($row);
        self::assertTrue($row->embedding_present);
        self::assertSame($otherSource->id, $row->source_id);
        self::assertSame($otherSource->organization_id, $row->organization_id);
        self::assertSame($project->id, $row->project_id);
        DB::table('ai_rag_chunks')->where('id', $chunk)->update(['embedding' => null, 'project_id' => null]);
        self::assertFalse(DB::table('ai_rag_status_chunks')->where('id', $chunk)->value('embedding_present'));
        self::assertNull(DB::table('ai_rag_status_chunks')->where('id', $chunk)->value('project_id'));
        DB::table('ai_rag_chunks')->where('id', $chunk)->delete();
        self::assertFalse(DB::table('ai_rag_status_chunks')->where('id', $chunk)->exists());
    }

    public function test_reconciliation_and_unchanged_embedding_presence_do_not_rewrite_projection_rows(): void
    {
        $source = $this->source();
        $chunk = $this->chunk($source, $this->vector(1.0));
        $sourceTuple = $this->tuple('ai_rag_status_sources', $source->id);
        $chunkTuple = $this->tuple('ai_rag_status_chunks', $chunk);
        $source->forceFill(['last_reconciled_at' => now()])->save();
        $source->forceFill(['metadata' => ['assistant_public_schema_revision' => 'revision-1', 'private' => 'changed']])->save();
        DB::table('ai_rag_chunks')->where('id', $chunk)->update(['embedding' => $this->vector(2.0)]);
        self::assertSame($sourceTuple, $this->tuple('ai_rag_status_sources', $source->id));
        self::assertSame($chunkTuple, $this->tuple('ai_rag_status_chunks', $chunk));
        try {
            DB::transaction(function () use ($source, $chunk): void {
                DB::table('ai_rag_sources')->where('id', $source->id)->update(['metadata' => '{}']);
                DB::table('ai_rag_chunks')->where('id', $chunk)->update(['embedding' => null]);
                throw new RuntimeException('rollback-projection');
            });
            self::fail('The projection mutation must roll back');
        } catch (RuntimeException $exception) {
            self::assertSame('rollback-projection', $exception->getMessage());
        }
        self::assertSame(['assistant_public_schema_revision' => 'revision-1'], json_decode(DB::table('ai_rag_status_sources')->where('id', $source->id)->value('metadata'), true));
        self::assertTrue(DB::table('ai_rag_status_chunks')->where('id', $chunk)->value('embedding_present'));
    }

    public function test_backfill_recovers_existing_sources_and_chunks(): void
    {
        $source = $this->source();
        $chunk = $this->chunk($source, $this->vector(1.0));
        DB::table('ai_rag_status_sources')->where('id', $source->id)->delete();
        DB::table('ai_rag_status_chunks')->where('id', $chunk)->delete();
        $migration = require database_path('migrations/2026_10_04_030000_add_rag_status_read_projections.php');
        (new ReflectionMethod($migration, 'backfill'))->invoke($migration);
        self::assertTrue(DB::table('ai_rag_status_sources')->where('id', $source->id)->exists());
        self::assertTrue(DB::table('ai_rag_status_chunks')->where('id', $chunk)->where('source_id', $source->id)->where('embedding_present', true)->exists());
    }

    private function source(): RagSource
    {
        $organization = Organization::factory()->create();

        return RagSource::query()->create([
            'organization_id' => $organization->id, 'source_type' => 'project', 'entity_type' => 'project',
            'entity_id' => '1', 'title' => 'Source', 'checksum' => hash('sha256', 'source'),
            'metadata' => ['assistant_public_schema_revision' => 'revision-1', 'private' => str_repeat('x', 10000)],
        ]);
    }

    private function chunk(RagSource $source, ?string $embedding = null): int
    {
        return DB::table('ai_rag_chunks')->insertGetId([
            'source_id' => $source->id, 'organization_id' => $source->organization_id, 'project_id' => null,
            'chunk_index' => 0, 'content' => 'Chunk', 'content_hash' => hash('sha256', 'chunk'),
            'embedding' => $embedding, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function vector(float $value): string
    {
        return '['.implode(',', RagTestEmbedding::fromLeadingValues([$value])).']';
    }

    private function tuple(string $table, int $id): string
    {
        return DB::table($table)->where('id', $id)->selectRaw('ctid::text AS tuple')->first()->tuple;
    }
}
