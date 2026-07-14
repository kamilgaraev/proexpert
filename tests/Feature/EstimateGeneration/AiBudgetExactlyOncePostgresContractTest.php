<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AiBudgetExactlyOncePostgresContractTest extends TestCase
{
    private const SECOND_CONNECTION = 'ai_budget_contract_secondary';

    public function refreshDatabase(): void {}

    public function test_concurrent_reserve_claim_settlement_and_reconciliation_are_exactly_once(): void
    {
        $this->requireOptInPostgres();
        $fixture = $this->fixture();
        $price = json_encode([
            'input_per_million' => '1.00',
            'cached_input_per_million' => '0.25',
            'output_per_million' => '2.00',
            'reasoning_mode' => 'excluded_from_output',
            'currency' => 'RUB',
            'source' => 'contract',
            'version' => 'postgres-contract-v1',
            'effective_at' => '2026-07-14T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR);
        $correlation = (string) str()->uuid();
        $attempt = (string) str()->uuid();
        $fingerprint = 'sha256:'.hash('sha256', $attempt.'|'.$correlation.'|exact');
        DB::selectOne('SELECT * FROM eg_pin_ai_operation_settings(?, ?, ?)', [
            $correlation, $fixture['organization_id'], $fixture['session_id'],
        ]);
        $reserve = 'SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) AS reservation_id';
        $bindings = [
            $attempt, $correlation, $fixture['organization_id'], $fixture['session_id'],
            $fixture['global_snapshot_id'], $fixture['organization_snapshot_id'],
            '1.00000000', 'RUB', $price, $fingerprint,
        ];

        $reservations = $this->race([
            [DB::getDefaultConnection(), $reserve, $bindings],
            [self::SECOND_CONNECTION, $reserve, $bindings],
        ]);
        self::assertSame($reservations[0]['reservation_id'], $reservations[1]['reservation_id']);

        try {
            DB::selectOne($reserve, [...array_slice($bindings, 0, 9), 'sha256:'.str_repeat('f', 64)]);
            self::fail('Fingerprint conflict was accepted.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('estimate_generation_ai_budget_attempt_conflict', $exception->getMessage());
        }

        $claims = $this->race([
            [DB::getDefaultConnection(), 'SELECT eg_claim_ai_budget_wire(?) AS claimed', [$attempt]],
            [self::SECOND_CONNECTION, 'SELECT eg_claim_ai_budget_wire(?) AS claimed', [$attempt]],
        ]);
        $claimValues = array_map(static fn (array $row): bool => (bool) $row['claimed'], $claims);
        sort($claimValues);
        self::assertSame([false, true], $claimValues);

        $resolved = $this->race([
            [DB::getDefaultConnection(), 'SELECT eg_settle_ai_budget(?, ?, ?) AS settled', [$attempt, '0.50000000', 'RUB']],
            [self::SECOND_CONNECTION, 'SELECT eg_mark_ai_budget_reconciliation(?) AS pending', [$attempt]],
        ]);
        self::assertTrue((bool) $resolved[0]['settled']);
        self::assertTrue((bool) $resolved[1]['pending']);
        $settled = DB::table('estimate_generation_ai_budget_reservations')->where('attempt_id', $attempt)->first();
        self::assertSame('settled', $settled?->status);
        self::assertSame('0.50000000', (string) $settled?->actual_amount);

        $ambiguousAttempt = $this->reserveAttempt($fixture, $price, 'ambiguous');
        self::assertTrue((bool) DB::selectOne('SELECT eg_claim_ai_budget_wire(?) AS claimed', [$ambiguousAttempt])->claimed);
        DB::table('estimate_generation_ai_budget_reservations')->where('attempt_id', $ambiguousAttempt)->update([
            'expires_at' => now()->subMinute(),
        ]);
        $reconciled = $this->race([
            [DB::getDefaultConnection(), 'SELECT eg_reconcile_expired_ai_budgets(?) AS reconciled', [10]],
            [self::SECOND_CONNECTION, 'SELECT eg_reconcile_expired_ai_budgets(?) AS reconciled', [10]],
        ]);
        self::assertSame(1, array_sum(array_map(static fn (array $row): int => (int) $row['reconciled'], $reconciled)));
        $ambiguous = DB::table('estimate_generation_ai_budget_reservations')->where('attempt_id', $ambiguousAttempt)->first();
        self::assertSame('reconciliation_required', $ambiguous?->status);
        self::assertNull($ambiguous?->actual_amount);

        $preWireAttempt = $this->reserveAttempt($fixture, $price, 'pre-wire');
        DB::table('estimate_generation_ai_budget_reservations')->where('attempt_id', $preWireAttempt)->update([
            'expires_at' => now()->subMinute(),
        ]);
        DB::selectOne('SELECT eg_reconcile_expired_ai_budgets(?) AS reconciled', [10]);
        $released = DB::table('estimate_generation_ai_budget_reservations')->where('attempt_id', $preWireAttempt)->first();
        self::assertSame('released', $released?->status);
        self::assertSame('0.00000000', (string) $released?->actual_amount);
    }

    /** @return array{organization_id: int, session_id: int, global_snapshot_id: int, organization_snapshot_id: int} */
    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $project = Project::factory()->create(['organization_id' => $organization->id]);
        $admin = SystemAdmin::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => EstimateGenerationStatus::Draft,
            'processing_stage' => 'created',
            'processing_progress' => 0,
            'input_payload' => [],
            'problem_flags' => [],
        ]);
        $organizationSnapshot = $this->snapshot();
        $globalSnapshot = $organizationSnapshot;
        $globalSnapshot['budgets'] = ['daily' => '1000.00', 'monthly' => '10000.00', 'currency' => 'RUB'];
        $globalVersion = ((int) DB::table('estimate_generation_setting_snapshots')
            ->where('scope', 'global')->max('version')) + 1;
        $globalId = (int) DB::table('estimate_generation_setting_snapshots')->insertGetId([
            'scope' => 'global', 'organization_id' => null, 'version' => $globalVersion,
            'snapshot' => json_encode($globalSnapshot, JSON_THROW_ON_ERROR),
            'snapshot_hash' => SettingsSnapshotHash::calculate($globalSnapshot),
            'daily_budget' => '1000.00', 'monthly_budget' => '10000.00', 'currency' => 'RUB',
            'created_by_system_admin_id' => $admin->id, 'created_at' => now(),
        ]);
        $organizationId = (int) DB::table('estimate_generation_setting_snapshots')->insertGetId([
            'scope' => 'organization', 'organization_id' => $organization->id, 'version' => 1,
            'snapshot' => json_encode($organizationSnapshot, JSON_THROW_ON_ERROR),
            'snapshot_hash' => SettingsSnapshotHash::calculate($organizationSnapshot),
            'daily_budget' => '100.00', 'monthly_budget' => '1000.00', 'currency' => 'RUB',
            'created_by_system_admin_id' => $admin->id, 'created_at' => now(),
        ]);
        foreach ([$globalId => $globalSnapshot, $organizationId => $organizationSnapshot] as $snapshotId => $canonicalSnapshot) {
            DB::table('estimate_generation_setting_snapshot_hashes')->insert([
                'setting_snapshot_id' => $snapshotId,
                'algorithm' => 'jcs-sha256-v1',
                'snapshot_hash' => SettingsSnapshotHash::calculate($canonicalSnapshot),
                'created_at' => now(),
            ]);
        }

        return [
            'organization_id' => (int) $organization->id,
            'session_id' => (int) $session->id,
            'global_snapshot_id' => $globalId,
            'organization_snapshot_id' => $organizationId,
        ];
    }

    /** @param array{organization_id: int, session_id: int, global_snapshot_id: int, organization_snapshot_id: int} $fixture */
    private function reserveAttempt(array $fixture, string $price, string $suffix): string
    {
        $correlation = (string) str()->uuid();
        $attempt = (string) str()->uuid();
        $fingerprint = 'sha256:'.hash('sha256', $attempt.'|'.$correlation.'|'.$suffix);
        DB::selectOne('SELECT * FROM eg_pin_ai_operation_settings(?, ?, ?)', [
            $correlation, $fixture['organization_id'], $fixture['session_id'],
        ]);
        DB::selectOne(
            'SELECT eg_reserve_ai_budget(?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?) AS reservation_id',
            [$attempt, $correlation, $fixture['organization_id'], $fixture['session_id'],
                $fixture['global_snapshot_id'], $fixture['organization_snapshot_id'], '1.00000000', 'RUB', $price, $fingerprint],
        );

        return $attempt;
    }

    /** @param list<array{string, string, list<mixed>}> $queries @return list<array<string, mixed>> */
    private function race(array $queries): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eg-ai-budget-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $go = $directory.DIRECTORY_SEPARATOR.'go';
        $children = [];
        foreach ($queries as $index => [$connection, $sql, $bindings]) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge($connection);
                touch($directory.DIRECTORY_SEPARATOR."ready-{$index}");
                $deadline = microtime(true) + 10;
                while (! is_file($go) && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                try {
                    $row = DB::connection($connection)->selectOne($sql, $bindings);
                    file_put_contents($directory.DIRECTORY_SEPARATOR."result-{$index}.json", json_encode((array) $row, JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($directory.DIRECTORY_SEPARATOR."error-{$index}.txt", $exception::class.'|'.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 10;
        while (count(glob($directory.DIRECTORY_SEPARATOR.'ready-*') ?: []) < count($queries) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        touch($go);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        $results = [];
        foreach (array_keys($queries) as $index) {
            $error = $directory.DIRECTORY_SEPARATOR."error-{$index}.txt";
            self::assertFileDoesNotExist($error, is_file($error) ? (string) file_get_contents($error) : '');
            $decoded = json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR."result-{$index}.json"), true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $results[] = $decoded;
        }

        return $results;
    }

    private function requireOptInPostgres(): void
    {
        $database = (string) DB::connection()->getDatabaseName();
        if (getenv('RUN_POSTGRES_AI_BUDGET_CONTRACT') !== '1'
            || DB::getDriverName() !== 'pgsql'
            || ! str_ends_with($database, '_contract')
            || ! function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires RUN_POSTGRES_AI_BUDGET_CONTRACT=1, a migrated disposable PostgreSQL contract database, and pcntl.');
        }
        $connection = config('database.connections.'.DB::getDefaultConnection());
        self::assertIsArray($connection);
        config(['database.connections.'.self::SECOND_CONNECTION => $connection]);
        DB::purge(self::SECOND_CONNECTION);
        self::assertInstanceOf(Connection::class, DB::connection(self::SECOND_CONNECTION));
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'schema_version' => 2,
            'models' => ['vision' => 'timeweb/vision-v2', 'classification' => 'timeweb/classify-v1', 'normative_matching' => 'timeweb/rerank-v1'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 120, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 45, 'classification' => 30, 'normative_matching' => 20],
            'retries' => ['vision' => 2, 'classification' => 1, 'normative_matching' => 2],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7800', 'normative_matching' => '0.8200'],
            'enabled_formats' => ['pdf'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '100.00', 'monthly' => '1000.00', 'currency' => 'RUB'],
        ];
    }
}
