<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantDocumentPolicySnapshotTrackingTest extends TestCase
{
    private const RELATIONS = ['public.ai_assistant_document_processing_policies'];

    public function beginDatabaseTransaction(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-z0-9]+_testing$/i', DB::connection()->getDatabaseName());
    }

    public function test_document_policy_table_keeps_snapshot_epoch_cacheable(): void
    {
        $epoch = new AssistantStatusSnapshotEpoch;
        $state = $this->readOnly(fn (): array => $epoch->capture(self::RELATIONS));
        self::assertTrue($state['proof_checks']['global_mutation_triggers']);
        self::assertTrue($state['cacheable']);
    }

    public function test_document_policy_tracking_repair_restores_proof_and_tracks_statement_mutations(): void
    {
        $epoch = new AssistantStatusSnapshotEpoch;
        $capture = fn (array $relations): array => $this->readOnly(fn (): array => $epoch->capture($relations));
        $migration = require base_path('database/migrations/2026_10_23_140000_track_assistant_document_policy_snapshot_mutations.php');
        self::assertInstanceOf(ForwardOnlyMigration::class, $migration);
        self::assertTrue($capture(self::RELATIONS)['cacheable']);

        try {
            foreach (self::RELATIONS as $relation) {
                DB::statement('DROP TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON '.$relation);
            }
            $missing = $capture(self::RELATIONS);
            self::assertFalse($missing['cacheable']);
            self::assertFalse($missing['proof_checks']['global_mutation_triggers']);
            $migration->up();
            self::assertTrue($capture(self::RELATIONS)['cacheable']);

            $triggerSql = 'SELECT tgrelid, oid, tgenabled, tgtype, tgfoid = to_regprocedure(?) AS expected_function FROM pg_trigger WHERE tgrelid = to_regclass(?) AND tgname = ? ORDER BY tgrelid';
            $triggerBindings = ['public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()', ...self::RELATIONS, AssistantStatusSnapshotEpoch::TRIGGER_NAME];
            $triggers = DB::select($triggerSql, $triggerBindings);
            self::assertCount(1, $triggers);
            foreach ($triggers as $trigger) {
                self::assertSame('A', $trigger->tgenabled);
                self::assertSame(60, $trigger->tgtype);
                self::assertTrue($trigger->expected_function);
            }
            $migration->up();
            self::assertEquals($triggers, DB::select($triggerSql, $triggerBindings));

            foreach (self::RELATIONS as $relation) {
                foreach (['INSERT INTO '.$relation.' SELECT * FROM '.$relation.' WHERE false', 'UPDATE '.$relation.' SET updated_at = updated_at WHERE false', 'DELETE FROM '.$relation.' WHERE false'] as $mutation) {
                    $before = $capture([$relation]);
                    self::assertTrue($before['cacheable']);
                    self::assertTrue($this->readOnly(fn (): bool => $epoch->isValid($before, 300)));
                    DB::statement($mutation);
                    self::assertFalse($this->readOnly(fn (): bool => $epoch->isValid($before, 300)));
                    self::assertTrue($capture([$relation])['cacheable']);
                }
            }
            $beforeTruncate = array_map(fn (string $relation): array => $capture([$relation]), self::RELATIONS);
            foreach ($beforeTruncate as $before) {
                self::assertTrue($this->readOnly(fn (): bool => $epoch->isValid($before, 300)));
            }
            DB::statement('TRUNCATE '.implode(', ', self::RELATIONS));
            foreach ($beforeTruncate as $before) {
                self::assertFalse($this->readOnly(fn (): bool => $epoch->isValid($before, 300)));
            }
            self::assertTrue($capture(self::RELATIONS)['cacheable']);
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

