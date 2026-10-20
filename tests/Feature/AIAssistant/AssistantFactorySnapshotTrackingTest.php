<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantFactorySnapshotTrackingTest extends TestCase
{
    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    public function test_factory_tracking_migration_restores_proof_and_invalidates_both_relations(): void
    {
        $epoch = new AssistantStatusSnapshotEpoch;
        $relations = ['public.estimate_generation_factory_runs', 'public.estimate_generation_factory_checkpoints'];
        $capture = fn (): array => $this->readOnly(fn (): array => $epoch->capture($relations));
        $migration = require base_path('database/migrations/2026_10_20_090000_track_estimate_factory_snapshot_mutations.php');
        self::assertInstanceOf(ForwardOnlyMigration::class, $migration);
        self::assertTrue($capture()['cacheable']);

        try {
            foreach ($relations as $relation) {
                DB::statement('DROP TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON '.$relation);
            }
            $missing = $capture();
            self::assertFalse($missing['cacheable']);
            self::assertFalse($missing['proof_checks']['global_mutation_triggers']);
            $migration->up();
            self::assertTrue($capture()['cacheable']);

            $triggers = DB::select('SELECT tgrelid, oid, tgenabled, tgtype, tgfoid = to_regprocedure(?) AS expected_function FROM pg_trigger WHERE tgrelid IN (to_regclass(?), to_regclass(?)) AND tgname = ? ORDER BY tgrelid', ['public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()', ...$relations, AssistantStatusSnapshotEpoch::TRIGGER_NAME]);
            self::assertCount(2, $triggers);
            foreach ($triggers as $trigger) {
                self::assertSame('A', $trigger->tgenabled);
                self::assertSame(60, $trigger->tgtype);
                self::assertTrue($trigger->expected_function);
            }
            $migration->up();
            self::assertEquals($triggers, DB::select('SELECT tgrelid, oid, tgenabled, tgtype, tgfoid = to_regprocedure(?) AS expected_function FROM pg_trigger WHERE tgrelid IN (to_regclass(?), to_regclass(?)) AND tgname = ? ORDER BY tgrelid', ['public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()', ...$relations, AssistantStatusSnapshotEpoch::TRIGGER_NAME]));

            foreach ($relations as $relation) {
                $before = $capture();
                self::assertTrue($before['cacheable']);
                self::assertTrue($this->readOnly(fn (): bool => $epoch->isValid($before, 300)));
                DB::statement('UPDATE '.$relation.' SET updated_at = updated_at WHERE false');
                self::assertFalse($this->readOnly(fn (): bool => $epoch->isValid($before, 300)));
                self::assertTrue($capture()['cacheable']);
            }
        } finally {
            $migration->up();
        }
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
