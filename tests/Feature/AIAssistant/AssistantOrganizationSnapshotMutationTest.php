<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageStateStore;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusScopedMutationGuard;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AssistantOrganizationSnapshotMutationTest extends TestCase
{
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
        self::assertSame(AssistantStatusScopedMutationGuard::WITNESS_BODY_HASH, hash('sha256', AssistantStatusScopedMutationGuard::WITNESS_BODY));
        $this->epoch = new AssistantStatusSnapshotEpoch;
    }

    public function test_other_organization_mutations_preserve_only_the_bound_snapshot(): void
    {
        $source = $this->source();
        $other = $this->source();
        $state = $this->capture((int) $source->organization_id);
        $otherState = $this->capture((int) $other->organization_id);
        $legacy = $this->capture(null);
        app(RagCoverageStateStore::class)->markIndexChanged((int) $other->organization_id);
        $other->forceFill(['metadata' => ['assistant_public_schema_revision' => 'changed']])->save();
        DB::table('ai_rag_status_sources')->where('id', $other->id)->update(['chunk_count' => 7, 'indexed_chunk_count' => 7]);

        self::assertTrue($this->valid($state));
        self::assertFalse($this->valid($otherState));
        self::assertFalse($this->valid($legacy));
        self::assertFalse($this->readOnly(fn (): bool => $this->epoch->isValid($state, 60, (int) $other->organization_id)));
        self::assertFalse($this->readOnly(fn (): bool => $this->epoch->isValid($state, 60)));
        DB::table('ai_rag_status_sources')->where('id', $source->id)->update(['chunk_count' => 8, 'indexed_chunk_count' => 8]);
        self::assertFalse($this->valid($state));
        $state = $this->capture((int) $source->organization_id);
        app(RagCoverageStateStore::class)->markIndexChanged((int) $source->organization_id);
        self::assertFalse($this->valid($state));
    }

    public function test_current_source_and_chunk_insert_update_delete_and_scope_transfer_invalidate(): void
    {
        $source = $this->source();
        $other = $this->source();
        $chunkId = DB::table('ai_rag_chunks')->insertGetId(['source_id' => $source->id, 'organization_id' => $source->organization_id,
            'project_id' => null, 'chunk_index' => 0, 'content' => 'fixture', 'content_hash' => hash('sha256', 'fixture'), 'created_at' => now(), 'updated_at' => now()]);
        $state = $this->capture((int) $source->organization_id);
        $otherState = $this->capture((int) $other->organization_id);
        DB::table('ai_rag_chunks')->where('id', $chunkId)->update(['organization_id' => $other->organization_id]);
        self::assertFalse($this->valid($state));
        self::assertFalse($this->valid($otherState));
        $state = $this->capture((int) $other->organization_id);
        DB::table('ai_rag_chunks')->where('id', $chunkId)->delete();
        self::assertFalse($this->valid($state));
        $state = $this->capture((int) $source->organization_id);
        $otherState = $this->capture((int) $other->organization_id);
        $source->forceFill(['organization_id' => $other->organization_id])->save();
        self::assertFalse($this->valid($state));
        self::assertFalse($this->valid($otherState));
        $state = $this->capture((int) $other->organization_id);
        $inserted = $this->source((int) $other->organization_id);
        self::assertFalse($this->valid($state));
        $state = $this->capture((int) $other->organization_id);
        $inserted->delete();
        self::assertFalse($this->valid($state));
    }

    public function test_timestamp_only_updates_and_rollback_preserve_but_truncate_invalidates_all_scopes(): void
    {
        $source = $this->source();
        $other = $this->source();
        $state = $this->capture((int) $source->organization_id);
        $source->forceFill(['last_reconciled_at' => now()->addMinute()])->save();
        self::assertTrue($this->valid($state));
        DB::beginTransaction();
        DB::table('ai_rag_status_sources')->where('id', $source->id)->update(['chunk_count' => 3, 'indexed_chunk_count' => 3]);
        DB::rollBack();
        self::assertTrue($this->valid($state));
        $otherState = $this->capture((int) $other->organization_id);
        DB::statement('TRUNCATE public.ai_rag_status_sources');
        self::assertFalse($this->valid($state));
        self::assertFalse($this->valid($otherState));
        self::assertGreaterThan(0, DB::table(AssistantStatusScopedMutationGuard::CHANGE_TABLE)->where('organization_id', 0)->count());
    }

    public function test_non_rag_authorization_relations_still_invalidate_globally(): void
    {
        $source = $this->source();
        $other = $this->source();
        $state = $this->capture((int) $source->organization_id, ['public.ai_rag_sources', 'public.organizations']);
        DB::table('organizations')->where('id', $other->organization_id)->update(['name' => 'Changed global input']);
        self::assertFalse($this->valid($state));
    }

    public function test_temporary_guard_gaps_in_a_partially_tracked_transaction_use_the_global_fallback(): void
    {
        $source = $this->source();
        $other = $this->source();
        $state = $this->capture((int) $source->organization_id);
        DB::transaction(function () use ($source, $other): void {
            DB::table('ai_rag_status_sources')->where('id', $other->id)->update(['chunk_count' => 2, 'indexed_chunk_count' => 2]);
            DB::statement('ALTER TABLE public.ai_rag_status_sources DISABLE TRIGGER '.AssistantStatusScopedMutationGuard::ROW_TRIGGER);
            DB::table('ai_rag_status_sources')->where('id', $source->id)->update(['chunk_count' => 3, 'indexed_chunk_count' => 3]);
            DB::statement('ALTER TABLE public.ai_rag_status_sources ENABLE ALWAYS TRIGGER '.AssistantStatusScopedMutationGuard::ROW_TRIGGER);
        });
        self::assertFalse($this->valid($state));
        self::assertGreaterThan(0, DB::table(AssistantStatusScopedMutationGuard::CHANGE_TABLE)->where('organization_id', 0)->count());
        $state = $this->capture((int) $source->organization_id);
        DB::statement('ALTER TABLE public.ai_rag_status_sources DISABLE TRIGGER '.AssistantStatusScopedMutationGuard::TRUNCATE_TRIGGER);
        try {
            DB::statement('TRUNCATE public.ai_rag_status_sources');
        } finally {
            DB::statement('ALTER TABLE public.ai_rag_status_sources ENABLE ALWAYS TRIGGER '.AssistantStatusScopedMutationGuard::TRUNCATE_TRIGGER);
        }
        self::assertFalse($this->valid($state));
    }

    public function test_actual_no_op_source_updates_preserve_scope_but_legacy_proof_stays_conservative(): void
    {
        $source = $this->source();
        $state = $this->capture((int) $source->organization_id);
        $legacy = $this->capture(null);
        DB::table('ai_rag_sources')->where('id', $source->id)->update(['checksum' => $source->checksum]);
        DB::table('ai_rag_sources')->where('id', -1)->update(['checksum' => hash('sha256', 'missing')]);
        self::assertTrue($this->valid($state));
        self::assertFalse($this->valid($legacy));
    }

    public function test_temporary_function_and_control_body_changes_cannot_hide_an_own_mutation(): void
    {
        $source = $this->source();
        $state = $this->capture((int) $source->organization_id);
        $canonical = AssistantStatusScopedMutationGuard::rowBody(AssistantStatusScopedMutationGuard::sourceColumns(DB::connection()));
        DB::transaction(function () use ($source, $canonical): void {
            $noop = "\nBEGIN\n RETURN NULL;\nEND;\n";
            DB::unprepared('CREATE OR REPLACE FUNCTION public.'.AssistantStatusScopedMutationGuard::ROW_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.DB::connection()->getPdo()->quote($noop));
            DB::table(AssistantStatusSnapshotEpoch::CONTROL_TABLE)->where('id', 1)->update(['scoped_guard_body' => $noop]);
            DB::table('ai_rag_status_sources')->where('id', $source->id)->update(['chunk_count' => 4, 'indexed_chunk_count' => 4]);
            DB::unprepared('CREATE OR REPLACE FUNCTION public.'.AssistantStatusScopedMutationGuard::ROW_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.DB::connection()->getPdo()->quote($canonical));
            DB::table(AssistantStatusSnapshotEpoch::CONTROL_TABLE)->where('id', 1)->update(['scoped_guard_body' => $canonical]);
        });
        self::assertFalse($this->valid($state));
        self::assertGreaterThan(0, DB::table(AssistantStatusScopedMutationGuard::CHANGE_TABLE)->where('organization_id', 0)->count());
    }

    public function test_scope_migration_rollback_and_reinstall_invalidate_previous_generations(): void
    {
        $source = $this->source();
        $state = $this->capture((int) $source->organization_id);
        $migration = require base_path('database/migrations/2026_10_08_010000_scope_rag_snapshot_mutations_to_organization.php');
        $migration->down();
        try {
            self::assertFalse($this->valid($state));
            self::assertFalse($this->capture((int) $source->organization_id, assertCacheable: false)['cacheable']);
        } finally {
            $migration->up();
        }
        self::assertFalse($this->valid($state));
        self::assertTrue($this->valid($this->capture((int) $source->organization_id)));
    }

    public function test_missing_or_changed_scope_guards_and_schema_fail_closed(): void
    {
        $source = $this->source();
        $state = $this->capture((int) $source->organization_id);
        DB::statement('ALTER TABLE public.ai_rag_status_sources DISABLE TRIGGER '.AssistantStatusScopedMutationGuard::ROW_TRIGGER);
        try {
            self::assertFalse($this->valid($state));
            self::assertFalse($this->capture((int) $source->organization_id, assertCacheable: false)['cacheable']);
        } finally {
            DB::statement('ALTER TABLE public.ai_rag_status_sources ENABLE ALWAYS TRIGGER '.AssistantStatusScopedMutationGuard::ROW_TRIGGER);
        }
        DB::statement('ALTER TABLE public.ai_rag_sources ADD COLUMN scoped_epoch_test boolean NOT NULL DEFAULT false');
        try {
            self::assertFalse($this->valid($state));
            self::assertFalse($this->capture((int) $source->organization_id, assertCacheable: false)['cacheable']);
        } finally {
            DB::statement('ALTER TABLE public.ai_rag_sources DROP COLUMN scoped_epoch_test');
        }
        $body = AssistantStatusScopedMutationGuard::rowBody(AssistantStatusScopedMutationGuard::sourceColumns(DB::connection()));
        DB::unprepared('CREATE OR REPLACE FUNCTION public.'.AssistantStatusScopedMutationGuard::ROW_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.DB::connection()->getPdo()->quote("\nBEGIN\n RETURN NULL;\nEND;\n"));
        try {
            self::assertFalse($this->valid($state));
            self::assertFalse($this->capture((int) $source->organization_id, assertCacheable: false)['cacheable']);
        } finally {
            DB::unprepared('CREATE OR REPLACE FUNCTION public.'.AssistantStatusScopedMutationGuard::ROW_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.DB::connection()->getPdo()->quote($body));
        }
        self::assertTrue($this->capture((int) $source->organization_id)['cacheable']);
    }

    public function test_late_commit_and_gc_cannot_restore_a_scoped_proof(): void
    {
        $source = $this->source();
        config(['database.connections.assistant_scope_writer' => DB::connection()->getConfig()]);
        $writer = DB::connection('assistant_scope_writer');
        try {
            $writer->beginTransaction();
            $writer->table('ai_rag_status_sources')->where('id', $source->id)->update(['chunk_count' => 2, 'indexed_chunk_count' => 2]);
            $writer->update('UPDATE public.'.AssistantStatusScopedMutationGuard::CHANGE_TABLE." SET created_at = clock_timestamp() - interval '1 hour' WHERE xid = pg_current_xact_id()");
            $state = $this->capture((int) $source->organization_id);
            self::assertTrue($this->valid($state));
            $writer->commit();
            self::assertFalse($this->valid($state));
            self::assertGreaterThan(0, $this->epoch->purge());
            self::assertFalse($this->valid($state));
            self::assertTrue($this->valid($this->capture((int) $source->organization_id)));
        } finally {
            if ($writer->transactionLevel() > 0) { $writer->rollBack(); }
            DB::purge('assistant_scope_writer');
        }
    }

    private function source(?int $organizationId = null): RagSource
    {
        $organizationId ??= (int) Organization::factory()->create()->id;

        return RagSource::query()->create(['organization_id' => $organizationId, 'project_id' => null,
            'source_type' => 'project', 'entity_type' => 'project', 'entity_id' => bin2hex(random_bytes(8)),
            'identity_part_key' => '', 'title' => 'Scope fixture', 'checksum' => hash('sha256', 'fixture'), 'metadata' => null]);
    }

    private function capture(?int $organizationId, ?array $relations = null, bool $assertCacheable = true): array
    {
        $relations ??= array_map(static fn (string $table): string => 'public.'.$table, AssistantStatusScopedMutationGuard::TABLES);
        $state = $this->readOnly(fn (): array => $this->epoch->capture($relations, $organizationId));
        if ($assertCacheable) { self::assertTrue($state['cacheable'], json_encode($state, JSON_THROW_ON_ERROR)); }

        return $state;
    }

    private function valid(array $state): bool
    {
        return $this->readOnly(fn (): bool => $this->epoch->isValid($state, 60, $state['organization_id']));
    }

    private function readOnly(callable $operation): mixed
    {
        return DB::transaction(function () use ($operation): mixed {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $operation();
        });
    }
}
