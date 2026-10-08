<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantStatusSnapshotEpochTest extends TestCase
{
    private Migration $migration;

    private AssistantStatusSnapshotEpoch $epoch;

    private bool $restoreEpoch = false;

    private bool $createdLegalEpochFixture = false;

    private bool $renamedLegalEpochTable = false;

    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        $this->migration = require base_path('database/migrations/2026_10_05_010000_create_assistant_status_snapshot_epoch.php');
        $this->restoreEpoch = DB::selectOne('SELECT to_regclass(?) AS table_name', ['public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE])->table_name !== null;
        if ($this->restoreEpoch) {
            $this->migration->down();
        }
        DB::statement('CREATE TABLE public.assistant_snapshot_epoch_parent_test (id bigint PRIMARY KEY, organization_id bigint NOT NULL, hidden boolean NOT NULL)');
        DB::statement('CREATE TABLE public.assistant_snapshot_epoch_auth_test (id bigint PRIMARY KEY, actor_id bigint NOT NULL, permission text NOT NULL, is_active boolean NOT NULL)');
        DB::statement('CREATE TABLE public.assistant_snapshot_epoch_row_test (id bigint PRIMARY KEY, parent_id bigint REFERENCES public.assistant_snapshot_epoch_parent_test(id), payload text NULL)');
        DB::statement('CREATE TABLE public.assistant_snapshot_epoch_unrelated_test (id bigint PRIMARY KEY, payload text NULL)');
        DB::statement('INSERT INTO public.assistant_snapshot_epoch_parent_test VALUES (1, 11, false), (2, 99, true)');
        DB::statement("INSERT INTO public.assistant_snapshot_epoch_auth_test VALUES (1, 4, 'view', true)");
        DB::statement('INSERT INTO public.assistant_snapshot_epoch_row_test VALUES (1, 1, NULL)');
        DB::statement('INSERT INTO public.assistant_snapshot_epoch_unrelated_test VALUES (1, NULL)');
        $this->migration->up();
        $this->epoch = new AssistantStatusSnapshotEpoch;
    }

    protected function tearDown(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
        if (isset($this->migration)) {
            $this->migration->down();
            DB::statement('DROP VIEW IF EXISTS public.assistant_snapshot_epoch_view_test');
            DB::statement('DROP FUNCTION IF EXISTS public.assistant_snapshot_epoch_custom_test(bigint)');
            DB::statement('DROP MATERIALIZED VIEW IF EXISTS public.assistant_snapshot_epoch_materialized_test');
            DB::statement('DROP TABLE IF EXISTS public.assistant_snapshot_epoch_new_table_test');
            DB::statement('DROP TABLE IF EXISTS public.assistant_snapshot_epoch_row_test');
            DB::statement('DROP TABLE IF EXISTS public.assistant_snapshot_epoch_unrelated_test');
            DB::statement('DROP TABLE IF EXISTS public.assistant_snapshot_epoch_auth_test');
            DB::statement('DROP TABLE IF EXISTS public.assistant_snapshot_epoch_parent_test');
            if ($this->createdLegalEpochFixture) {
                DB::statement('DROP TABLE public.legal_acceptance_events');
                DB::statement('DROP FUNCTION IF EXISTS public.assistant_snapshot_legal_guard_test()');
            }
            if ($this->renamedLegalEpochTable) {
                DB::statement('ALTER TABLE public.assistant_snapshot_legal_original_test RENAME TO legal_acceptance_events');
            }
            if ($this->restoreEpoch) {
                $this->migration->up();
            }
        }
        parent::tearDown();
    }

    public function test_rollback_preserves_proof_and_statements_coalesce_by_top_level_transaction(): void
    {
        $state = $this->capture();
        self::assertTrue($state['cacheable'], $state['cacheable'] ? '' : $this->diagnostics($state));
        DB::beginTransaction();
        DB::statement("UPDATE public.assistant_snapshot_epoch_row_test SET payload = 'rolled-back'");
        self::assertSame(1, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->count());
        DB::rollBack();
        self::assertSame(0, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->count());
        self::assertTrue($this->valid($state));
        DB::transaction(function (): void {
            DB::statement("UPDATE public.assistant_snapshot_epoch_row_test SET payload = 'changed'");
            DB::statement('SAVEPOINT epoch_nested');
            DB::statement('UPDATE public.assistant_snapshot_epoch_auth_test SET is_active = false');
            DB::statement('UPDATE public.assistant_snapshot_epoch_parent_test SET hidden = false WHERE id = 2');
            self::assertSame(3, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->count());
            self::assertSame(1, (int) DB::selectOne('SELECT COUNT(DISTINCT xid) AS total FROM public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE)->total);
            DB::statement("UPDATE public.assistant_snapshot_epoch_row_test SET payload = 'same-transaction'");
            self::assertSame(3, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->count());
            $xid = DB::selectOne('SELECT pg_current_xact_id()::text AS xid')->xid;
            self::assertSame($xid, DB::selectOne('SELECT xid::text AS xid FROM public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE)->xid);
        });
        self::assertFalse($this->valid($state));
    }

    public function test_unrelated_relation_mutation_preserves_a_scoped_proof(): void
    {
        $metrics = new ApiQueryMetrics;
        request()->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
        $state = $this->capture();
        DB::statement("UPDATE public.assistant_snapshot_epoch_unrelated_test SET payload = 'request-metrics'");
        self::assertSame(1, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->count());
        self::assertTrue($this->valid($state));
        self::assertSame(['phase' => 'valid', 'relation' => null], $metrics->summary()['assistant_snapshot_epoch']);
        DB::statement('UPDATE public.assistant_snapshot_epoch_auth_test SET is_active = false');
        self::assertFalse($this->valid($state));
        self::assertSame(['phase' => 'relation_mutation_present', 'relation' => 'assistant_snapshot_epoch_auth_test'], $metrics->summary()['assistant_snapshot_epoch']);
    }

    public function test_nullable_delete_truncate_hidden_parent_and_auth_mutations_invalidate_proofs(): void
    {
        foreach ([
            'UPDATE public.assistant_snapshot_epoch_row_test SET payload = NULL',
            'UPDATE public.assistant_snapshot_epoch_parent_test SET organization_id = 88 WHERE id = 2',
            'UPDATE public.assistant_snapshot_epoch_auth_test SET is_active = false',
            'DELETE FROM public.assistant_snapshot_epoch_row_test WHERE id = 1',
            'TRUNCATE public.assistant_snapshot_epoch_row_test',
        ] as $statement) {
            $state = $this->capture();
            self::assertTrue($this->valid($state));
            DB::statement($statement);
            self::assertFalse($this->valid($state));
        }
    }

    public function test_late_out_of_order_commit_and_gc_cannot_restore_an_old_proof(): void
    {
        config(['database.connections.assistant_snapshot_epoch_writer' => DB::connection()->getConfig()]);
        $writer = DB::connection('assistant_snapshot_epoch_writer');
        self::assertSame(DB::connection()->getDatabaseName(), $writer->getDatabaseName());
        try {
            $writer->beginTransaction();
            $writer->statement("UPDATE public.assistant_snapshot_epoch_row_test SET payload = 'late'");
            $lateXid = $writer->selectOne('SELECT pg_current_xact_id()::text AS xid')->xid;
            $writer->update('UPDATE public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE." SET created_at = clock_timestamp() - interval '1 hour' WHERE xid = ?::xid8", [$lateXid]);
            $beforeBoth = $this->capture();
            DB::statement('UPDATE public.assistant_snapshot_epoch_auth_test SET is_active = false');
            self::assertFalse($this->valid($beforeBoth));
            $beforeLate = $this->capture();
            self::assertTrue($this->valid($beforeLate));
            self::assertSame(0, $this->epoch->purge());
            self::assertTrue($this->valid($beforeLate));
            $writer->commit();
            self::assertFalse($this->valid($beforeLate));
            self::assertSame(1, $this->epoch->purge());
            self::assertFalse($this->valid($beforeLate));
            self::assertTrue($this->valid($this->capture()));
        } finally {
            if ($writer->transactionLevel() > 0) {
                $writer->rollBack();
            }
            DB::purge('assistant_snapshot_epoch_writer');
        }
    }

    public function test_gc_invalidates_even_a_snapshot_that_already_saw_deleted_events(): void
    {
        DB::statement("UPDATE public.assistant_snapshot_epoch_row_test SET payload = 'before-capture'");
        $state = $this->capture();
        self::assertTrue($this->valid($state));
        DB::update('UPDATE public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE." SET created_at = clock_timestamp() - interval '1 hour'");
        self::assertSame(1, $this->epoch->purge());
        self::assertFalse($this->valid($state));
        $newState = $this->capture();
        self::assertSame(0, $this->epoch->purge());
        self::assertTrue($this->valid($newState));
        self::assertSame(1, $newState['gc_generation']);
    }

    public function test_gc_drains_oldest_events_in_bounded_batches_and_preserves_recent_events(): void
    {
        DB::insert('INSERT INTO public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE.' (xid, relation_oid, created_at) '
            .'SELECT pg_current_xact_id(), n::oid, statement_timestamp() - make_interval(secs => CASE '
            .'WHEN n <= 10000 THEN 3600 WHEN n <= 10003 THEN 1800 ELSE 0 END) FROM generate_series(1, 10004) n');
        $before = $this->capture();
        self::assertTrue($this->valid($before));

        self::assertSame(10000, $this->epoch->purge());
        self::assertSame([10001, 10002, 10003, 10004], array_map('intval', DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)
            ->orderBy('relation_oid')->pluck('relation_oid')->all()));
        self::assertFalse($this->valid($before));
        $afterFirst = $this->capture();
        self::assertSame(1, $afterFirst['gc_generation']);
        self::assertTrue($this->valid($afterFirst));

        self::assertSame(3, $this->epoch->purge());
        self::assertSame([10004], array_map('intval', DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->pluck('relation_oid')->all()));
        self::assertFalse($this->valid($afterFirst));
        $afterSecond = $this->capture();
        self::assertSame(2, $afterSecond['gc_generation']);
        self::assertTrue($this->valid($afterSecond));
        self::assertSame(0, $this->epoch->purge());
        self::assertTrue($this->valid($afterSecond));
        self::assertSame(2, $this->capture()['gc_generation']);
    }

    public function test_gc_rolls_back_deleted_events_when_generation_cannot_be_updated(): void
    {
        DB::statement("UPDATE public.assistant_snapshot_epoch_row_test SET payload = 'expired'");
        DB::update('UPDATE public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE." SET created_at = clock_timestamp() - interval '1 hour'");
        DB::table(AssistantStatusSnapshotEpoch::CONTROL_TABLE)->delete();

        try {
            $this->epoch->purge();
            self::fail('Missing control must prevent garbage collection');
        } catch (\LogicException $exception) {
            self::assertSame('assistant_snapshot_control_missing', $exception->getMessage());
        }

        self::assertSame(1, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->count());
    }

    public function test_ttl_starts_at_capture_and_unsafe_transaction_or_malformed_state_fails_closed(): void
    {
        self::assertFalse($this->epoch->capture()['cacheable']);
        $state = $this->capture();
        DB::select('SELECT pg_sleep(1.1)');
        $state['published_at'] = DB::selectOne('SELECT clock_timestamp()::text AS published_at')->published_at;
        self::assertFalse($this->valid($state, 1));
        self::assertTrue($this->valid($state, 300));
        self::assertFalse($this->valid(array_replace($state, ['snapshot' => 'broken'])));
        self::assertFalse($this->valid(array_replace($state, ['captured_at' => 'not-a-date'])));
        DB::beginTransaction();
        try {
            self::assertFalse($this->epoch->capture()['cacheable']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_schema_views_trigger_coverage_and_epoch_recreation_fail_closed(): void
    {
        $state = $this->capture();
        DB::statement('ALTER TABLE public.assistant_snapshot_epoch_row_test ADD COLUMN future_field text');
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        self::assertTrue($state['cacheable']);
        DB::statement('ALTER TABLE public.assistant_snapshot_epoch_row_test ENABLE ROW LEVEL SECURITY');
        self::assertFalse($this->capture()['cacheable']);
        DB::statement('ALTER TABLE public.assistant_snapshot_epoch_row_test DISABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.assistant_snapshot_epoch_row_test DISABLE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
        self::assertFalse($this->valid($state));
        self::assertFalse($this->capture()['cacheable']);
        DB::statement('ALTER TABLE public.assistant_snapshot_epoch_row_test ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
        DB::statement('CREATE TABLE public.assistant_snapshot_epoch_new_table_test (id bigint)');
        self::assertFalse($this->capture()['cacheable']);
        DB::statement('DROP TABLE public.assistant_snapshot_epoch_new_table_test');
        DB::statement('CREATE VIEW public.assistant_snapshot_epoch_view_test AS SELECT id FROM public.assistant_snapshot_epoch_row_test WHERE payload IS NULL');
        self::assertFalse($this->readOnly(fn (): array => $this->epoch->capture(['public.assistant_snapshot_epoch_view_test']))['cacheable']);
        $state = $this->capture();
        self::assertTrue($state['cacheable']);
        DB::statement('CREATE OR REPLACE VIEW public.assistant_snapshot_epoch_view_test AS SELECT id FROM public.assistant_snapshot_epoch_row_test WHERE payload IS NOT NULL');
        self::assertFalse($this->valid($state));
        DB::statement('CREATE MATERIALIZED VIEW public.assistant_snapshot_epoch_materialized_test AS SELECT id FROM public.assistant_snapshot_epoch_row_test');
        self::assertTrue($this->capture()['cacheable']);
        self::assertFalse($this->readOnly(fn (): array => $this->epoch->capture(['public.assistant_snapshot_epoch_materialized_test']))['cacheable']);
        self::assertFalse($this->readOnly(fn (): array => $this->epoch->capture(['public.missing_snapshot_relation']))['cacheable']);
        DB::statement('CREATE OR REPLACE VIEW public.assistant_snapshot_epoch_view_test AS SELECT id FROM public.assistant_snapshot_epoch_materialized_test');
        self::assertFalse($this->readOnly(fn (): array => $this->epoch->capture(['public.assistant_snapshot_epoch_view_test']))['cacheable']);
        DB::statement('CREATE FUNCTION public.assistant_snapshot_epoch_custom_test(bigint) RETURNS bigint LANGUAGE sql IMMUTABLE AS $fn$SELECT $1$fn$');
        DB::statement('CREATE OR REPLACE VIEW public.assistant_snapshot_epoch_view_test AS SELECT public.assistant_snapshot_epoch_custom_test(id) AS id FROM public.assistant_snapshot_epoch_row_test');
        self::assertFalse($this->readOnly(fn (): array => $this->epoch->capture(['public.assistant_snapshot_epoch_view_test']))['cacheable']);
        DB::statement('CREATE OR REPLACE VIEW public.assistant_snapshot_epoch_view_test AS SELECT id FROM public.assistant_snapshot_epoch_row_test');
        DB::statement('CREATE OR REPLACE VIEW public.assistant_snapshot_epoch_view_test AS SELECT id FROM public.assistant_snapshot_epoch_row_test WHERE pg_catalog.now() IS NOT NULL');
        self::assertFalse($this->readOnly(fn (): array => $this->epoch->capture(['public.assistant_snapshot_epoch_view_test']))['cacheable']);
        DB::statement('CREATE OR REPLACE VIEW public.assistant_snapshot_epoch_view_test AS SELECT id FROM public.assistant_snapshot_epoch_row_test');
        DB::statement('DROP MATERIALIZED VIEW public.assistant_snapshot_epoch_materialized_test');
        $state = $this->capture();
        $this->migration->down();
        self::assertFalse($this->valid($state));
        $this->migration->up();
        self::assertFalse($this->valid($state));
        self::assertTrue($this->capture()['cacheable']);
    }

    public function test_schema_fingerprint_preserves_escaped_identifiers_and_policy_changes(): void
    {
        $table = 'public.assistant_snapshot_epoch_unrelated_test';
        $names = ['codec,(a)', 'codec"a', 'codec\\a', 'codec{a}', 'codec NULL', 'кодек'];
        $quote = static fn (string $name): string => '"'.str_replace('"', '""', $name).'"';
        DB::statement('ALTER TABLE '.$table.' ADD COLUMN '.$quote($names[0]).' text');
        foreach (array_slice($names, 1) as $index => $name) {
            $state = $this->capture();
            self::assertTrue($state['cacheable']);
            self::assertTrue($this->valid($state));
            DB::statement('ALTER TABLE '.$table.' RENAME COLUMN '.$quote($names[$index]).' TO '.$quote($name));
            self::assertFalse($this->valid($state));
        }
        DB::statement('CREATE POLICY "codec,(policy)" ON '.$table.' USING (payload IS NULL)');
        $state = $this->capture();
        self::assertTrue($state['cacheable']);
        self::assertTrue($this->valid($state));
        DB::statement('ALTER POLICY "codec,(policy)" ON '.$table.' USING (payload IS NOT NULL)');
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        self::assertTrue($this->valid($state));
        DB::statement('ALTER POLICY "codec,(policy)" ON '.$table.' TO CURRENT_USER');
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        self::assertTrue($this->valid($state));
        DB::statement('ALTER POLICY "codec,(policy)" ON '.$table.' WITH CHECK (payload IS NULL)');
        self::assertFalse($this->valid($state));
        $state = $this->capture();
        self::assertTrue($this->valid($state));
        DB::statement('ALTER POLICY "codec,(policy)" ON '.$table.' RENAME TO "codec""policy"');
        self::assertFalse($this->valid($state));
    }

    public function test_semantic_session_settings_invalidate_while_budget_settings_preserve_proof(): void
    {
        $state = $this->capture();
        $settings = DB::selectOne("SELECT current_setting('TimeZone') AS timezone, current_setting('DateStyle') AS datestyle, current_setting('search_path') AS search_path, current_setting('row_security') AS row_security");
        $changes = [
            ['TimeZone', $settings->timezone === 'UTC' ? 'Pacific/Kiritimati' : 'UTC'],
            ['DateStyle', $settings->datestyle === 'ISO, MDY' ? 'SQL, DMY' : 'ISO, MDY'],
            ['search_path', $settings->search_path === 'public' ? 'pg_catalog, public' : 'public'],
            ['row_security', $settings->row_security === 'on' ? 'off' : 'on'],
        ];
        foreach ($changes as [$name, $value]) {
            self::assertFalse($this->readOnly(function () use ($state, $name, $value): bool {
                DB::select('SELECT set_config(?, ?, true)', [$name, $value]);

                return $this->epoch->isValid($state);
            }));
        }
        self::assertTrue($this->readOnly(function () use ($state): bool {
            DB::statement("SET LOCAL work_mem = '16MB'");
            DB::statement('SET LOCAL jit = off');
            DB::statement('SET LOCAL statement_timeout = 60000');

            return $this->epoch->isValid($state);
        }));
    }

    public function test_late_legal_table_guard_restores_proof_and_preserves_existing_write_protection(): void
    {
        $this->isolateOptionalLegalTable();
        self::assertNull(DB::selectOne("SELECT to_regclass('public.legal_acceptance_events') AS relation")->relation);
        DB::statement('CREATE TABLE public.legal_acceptance_events (id bigint PRIMARY KEY)');
        $this->createdLegalEpochFixture = true;
        DB::unprepared("CREATE FUNCTION public.assistant_snapshot_legal_guard_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'immutable_test'; END; \$\$");
        DB::statement('CREATE TRIGGER assistant_snapshot_legal_existing_test BEFORE UPDATE OR DELETE ON public.legal_acceptance_events FOR EACH ROW EXECUTE FUNCTION public.assistant_snapshot_legal_guard_test()');
        $existing = DB::selectOne("SELECT pg_get_triggerdef(oid) AS definition,tgenabled FROM pg_trigger WHERE tgrelid='public.legal_acceptance_events'::regclass AND tgname='assistant_snapshot_legal_existing_test'");
        self::assertFalse($this->capture()['cacheable']);
        $repair = require base_path('database/migrations/2026_10_07_021000_track_legal_acceptance_events_for_assistant_snapshots.php');
        $repair->up();
        $repair->up();
        self::assertTrue($this->capture()['cacheable']);
        self::assertEquals($existing, DB::selectOne("SELECT pg_get_triggerdef(oid) AS definition,tgenabled FROM pg_trigger WHERE tgrelid='public.legal_acceptance_events'::regclass AND tgname='assistant_snapshot_legal_existing_test'"));
        $state = $this->readOnly(fn (): array => $this->epoch->capture(['public.legal_acceptance_events']));
        self::assertTrue($this->valid($state));
        DB::statement('INSERT INTO public.legal_acceptance_events VALUES (1)');
        self::assertFalse($this->valid($state));
        self::assertSame(1, DB::table(AssistantStatusSnapshotEpoch::CHANGE_TABLE)->whereRaw("relation_oid='public.legal_acceptance_events'::regclass::oid")->count());
        try {
            DB::statement('UPDATE public.legal_acceptance_events SET id=2 WHERE id=1');
            self::fail('Existing legal write protection must remain active');
        } catch (\Illuminate\Database\QueryException $exception) {
            self::assertStringContainsString('immutable_test', $exception->getMessage());
        }
        self::assertSame([1], DB::table('legal_acceptance_events')->pluck('id')->all());
        DB::statement('ALTER TABLE public.legal_acceptance_events DISABLE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
        self::assertFalse($this->capture()['cacheable']);
        $repair->up();
        self::assertTrue($this->capture()['cacheable']);
        $repair->down();
        self::assertTrue($this->capture()['cacheable']);
    }

    public function test_legal_epoch_repair_tolerates_absent_optional_table_and_epoch(): void
    {
        $this->isolateOptionalLegalTable();
        self::assertNull(DB::selectOne("SELECT to_regclass('public.legal_acceptance_events') AS relation")->relation);
        $repair = require base_path('database/migrations/2026_10_07_021000_track_legal_acceptance_events_for_assistant_snapshots.php');
        $repair->up();
        self::assertTrue($this->capture()['cacheable']);
        DB::statement('CREATE TABLE public.legal_acceptance_events (id bigint PRIMARY KEY)');
        $this->createdLegalEpochFixture = true;
        $this->migration->down();
        $repair->up();
        self::assertFalse(DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_trigger WHERE tgrelid='public.legal_acceptance_events'::regclass AND tgname=?) AS installed", [AssistantStatusSnapshotEpoch::TRIGGER_NAME])->installed);
        DB::statement('INSERT INTO public.legal_acceptance_events VALUES (1)');
        self::assertSame([1], DB::table('legal_acceptance_events')->pluck('id')->all());
        $this->migration->up();
        self::assertTrue($this->capture()['cacheable']);
    }

    private function isolateOptionalLegalTable(): void
    {
        if (DB::selectOne("SELECT to_regclass('public.legal_acceptance_events') AS relation")->relation !== null) {
            DB::statement('ALTER TABLE public.legal_acceptance_events RENAME TO assistant_snapshot_legal_original_test');
            $this->renamedLegalEpochTable = true;
        }
    }

    private function capture(): array
    {
        return $this->readOnly(fn (): array => $this->epoch->capture(['public.assistant_snapshot_epoch_row_test', 'public.assistant_snapshot_epoch_auth_test', 'public.assistant_snapshot_epoch_parent_test']));
    }

    private function diagnostics(array $state): string
    {
        $method = new \ReflectionMethod(AssistantStatusSnapshotEpoch::class, 'schemaState');
        $schema = $method->invoke($this->epoch, $state['used_relations']);
        $function = DB::selectOne('SELECT prosrc = ? AS body_matches, proconfig::text AS settings, prosecdef AS security_definer FROM pg_proc WHERE oid = to_regprocedure(?)', [AssistantStatusSnapshotEpoch::FUNCTION_BODY, 'public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()']);
        $excluded = implode(', ', array_map(static fn (string $table): string => "'".$table."'", AssistantStatusSnapshotEpoch::EXCLUDED_TABLES));
        $missing = DB::select("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND c.relname NOT IN (".$excluded.") AND NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = c.oid AND t.tgname = ? AND t.tgfoid = to_regprocedure(?) AND NOT t.tgisinternal AND t.tgtype = 60 AND t.tgenabled = 'A' AND t.tgconstraint = 0 AND t.tgnargs = 0 AND t.tgattr::text = '' AND t.tgqual IS NULL) LIMIT 5", [AssistantStatusSnapshotEpoch::TRIGGER_NAME, 'public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()']);
        $unsupported = DB::select("SELECT c.relname, c.relkind FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('m', 'f') LIMIT 5");
        $externalViews = DB::select("SELECT DISTINCT v.relname, target.relname AS target, ns.nspname FROM pg_class v JOIN pg_namespace vn ON vn.oid = v.relnamespace JOIN pg_rewrite w ON w.ev_class = v.oid JOIN pg_depend d ON d.classid = 'pg_rewrite'::regclass AND d.objid = w.oid AND d.refclassid = 'pg_class'::regclass JOIN pg_class target ON target.oid = d.refobjid JOIN pg_namespace ns ON ns.oid = target.relnamespace WHERE vn.nspname = 'public' AND v.relkind = 'v' AND target.oid <> v.oid AND (target.relkind IN ('m', 'f') OR ns.nspname NOT IN ('public', 'pg_catalog', 'information_schema')) LIMIT 5");

        return json_encode(['state' => $state, 'schema' => $schema, 'schema_flag_type' => get_debug_type($schema->cacheable),
            'function' => $function, 'missing' => $missing, 'unsupported' => $unsupported, 'external_views' => $externalViews,
            'columns' => DB::select("SELECT c.relname, a.attname, a.attnotnull, a.atttypid::regtype::text AS type FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_attribute a ON a.attrelid = c.oid WHERE n.nspname = 'public' AND c.relname IN (?, ?) AND a.attnum > 0 ORDER BY c.relname, a.attnum", [AssistantStatusSnapshotEpoch::CHANGE_TABLE, AssistantStatusSnapshotEpoch::CONTROL_TABLE])], JSON_THROW_ON_ERROR);
    }

    private function valid(array $state, int $ttlSeconds = 300): bool
    {
        return $this->readOnly(fn (): bool => $this->epoch->isValid($state, $ttlSeconds));
    }

    private function readOnly(callable $operation): mixed
    {
        DB::beginTransaction();
        try {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $result = $operation();
            DB::commit();

            return $result;
        } catch (\Throwable $exception) {
            DB::rollBack();
            throw $exception;
        }
    }
}
