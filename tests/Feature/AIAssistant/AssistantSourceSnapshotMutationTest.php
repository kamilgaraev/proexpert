<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSourceMutationGuard;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AssistantSourceSnapshotMutationTest extends TestCase
{
    private Migration $migration;

    private AssistantStatusSnapshotEpoch $epoch;

    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->migration = require base_path('database/migrations/2026_10_08_000100_guard_source_snapshot_semantic_updates.php');
        $this->migration->up();
        $this->epoch = new AssistantStatusSnapshotEpoch;
    }

    protected function tearDown(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::statement('DROP TRIGGER IF EXISTS assistant_source_before_test ON public.ai_rag_sources');
        DB::statement('DROP FUNCTION IF EXISTS public.assistant_source_before_test()');
        DB::statement('ALTER TABLE public.ai_rag_sources DROP COLUMN IF EXISTS snapshot_scope_test');
        $this->migration->up();
        parent::tearDown();
    }

    public function test_reconciliation_updates_sweep_timestamps_without_invalidating_source_proof(): void
    {
        $source = $this->source();
        $state = $this->capture();
        $timestamp = now()->addMinute()->startOfSecond();
        $source->forceFill(['last_reconciled_at' => $timestamp])->save();

        self::assertTrue($this->valid($state));
        self::assertTrue($timestamp->equalTo($source->fresh()->last_reconciled_at));
    }

    public function test_every_semantic_update_including_no_matching_rows_invalidates_source_proof(): void
    {
        foreach ([
            ['checksum' => hash('sha256', 'changed')], ['title' => 'Changed title'],
            ['metadata' => ['changed' => true]], ['source_version' => 'changed-version'],
            ['indexed_at' => now()->addMinute()], ['identity_part_key' => 'other-part'],
            ['entity_id' => 'other-entity'], ['entity_type' => 'other-type'], ['source_type' => 'other-source'],
            ['organization_id' => Organization::factory()->create()->id],
        ] as $attributes) {
            $source = $this->source();
            $state = $this->capture();
            $source->forceFill($attributes)->save();
            self::assertFalse($this->valid($state));
        }
        $state = $this->capture();
        DB::table('ai_rag_sources')->where('id', -1)->update(['checksum' => hash('sha256', 'no-row')]);
        self::assertFalse($this->valid($state));
    }

    public function test_before_mutations_are_tracked_even_after_the_before_trigger_is_removed(): void
    {
        foreach (["NEW.checksum := repeat('b', 64);", "NEW.metadata := 'null'::jsonb;"] as $mutation) {
            $source = $this->source();
            $state = $this->capture();
            DB::unprepared('CREATE FUNCTION public.assistant_source_before_test() RETURNS trigger LANGUAGE plpgsql AS '.DB::connection()->getPdo()->quote('BEGIN '.$mutation.' RETURN NEW; END;'));
            DB::statement('CREATE TRIGGER assistant_source_before_test BEFORE UPDATE ON public.ai_rag_sources FOR EACH ROW EXECUTE FUNCTION public.assistant_source_before_test()');
            DB::table('ai_rag_sources')->where('id', $source->id)->update(['last_reconciled_at' => now()->addMinute(), 'updated_at' => now()->addMinute()]);
            DB::statement('DROP TRIGGER assistant_source_before_test ON public.ai_rag_sources');
            DB::statement('DROP FUNCTION public.assistant_source_before_test()');
            self::assertFalse($this->valid($state));
        }
    }

    public function test_project_identity_changes_and_insert_delete_truncate_invalidate_proofs(): void
    {
        $source = $this->source();
        $project = Project::withoutEvents(fn () => Project::factory()->create(['organization_id' => $source->organization_id]));
        $state = $this->capture();
        $source->forceFill(['project_id' => $project->id])->save();
        self::assertSame((int) $project->id, (int) $source->fresh()->identity_project_id);
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        $inserted = $this->source();
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        $inserted->delete();
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        DB::statement('TRUNCATE public.ai_rag_sources CASCADE');
        self::assertFalse($this->valid($state));
    }

    public function test_incomplete_statement_guard_is_rejected_even_with_the_row_fallback(): void
    {
        $state = $this->capture();
        $columns = array_map(static fn (object $column): string => (string) $column->attname, DB::select("SELECT attname FROM pg_attribute WHERE attrelid = to_regclass('public.ai_rag_sources') AND attnum > 0 AND NOT attisdropped AND attname NOT IN ('last_reconciled_at', 'updated_at', 'checksum') ORDER BY attnum"));
        $list = implode(', ', array_map(AssistantStatusSourceMutationGuard::quoteColumn(...), $columns));
        DB::statement('CREATE OR REPLACE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR DELETE OR TRUNCATE OR UPDATE OF '.$list.' ON public.ai_rag_sources FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
        DB::statement('ALTER TABLE public.ai_rag_sources ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);

        self::assertFalse($this->capture(false)['cacheable']);
        self::assertFalse($this->valid($state));
    }

    public function test_unknown_columns_and_missing_row_guard_fail_closed(): void
    {
        $state = $this->capture();
        DB::statement('ALTER TABLE public.ai_rag_sources ADD COLUMN snapshot_scope_test boolean NOT NULL DEFAULT false');
        self::assertFalse($this->capture(false)['cacheable']);
        self::assertFalse($this->valid($state));
        DB::statement('ALTER TABLE public.ai_rag_sources DROP COLUMN snapshot_scope_test');
        DB::statement('DROP TRIGGER '.AssistantStatusSourceMutationGuard::ROW_TRIGGER.' ON public.ai_rag_sources');
        self::assertFalse($this->capture(false)['cacheable']);
        self::assertFalse($this->valid($state));
    }

    public function test_transition_invalidates_old_proofs_and_generic_rollback_remains_conservative(): void
    {
        $source = $this->source();
        $state = $this->capture();
        $this->migration->down();
        self::assertFalse($this->valid($state));
        $generic = $this->capture();
        self::assertTrue($generic['cacheable']);
        $source->forceFill(['last_reconciled_at' => now()->addMinute()])->save();
        self::assertFalse($this->valid($generic));
        $generic = $this->capture();
        $this->migration->up();
        self::assertFalse($this->valid($generic));
        self::assertTrue($this->capture()['cacheable']);
    }

    private function source(): RagSource
    {
        $organization = Organization::factory()->create();

        return RagSource::query()->create(['organization_id' => $organization->id, 'project_id' => null,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => (string) $organization->id,
            'identity_part_key' => '', 'title' => 'Source fixture', 'checksum' => hash('sha256', 'source'), 'metadata' => null]);
    }

    private function capture(bool $assertCacheable = true): array
    {
        $state = $this->readOnly(fn (): array => $this->epoch->capture(['public.ai_rag_sources']));
        if ($assertCacheable) {
            self::assertTrue($state['cacheable']);
        }

        return $state;
    }

    private function valid(array $state): bool
    {
        return $this->readOnly(fn (): bool => $this->epoch->isValid($state, 60));
    }

    private function readOnly(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $operation();
        });
    }
}
