<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdminApiTestContext;
use Tests\Support\EnablesImmutableAuditWriter;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class CommercialProposalConcurrencyTest extends TestCase
{
    use EnablesImmutableAuditWriter;

    private string $runtimeDirectory;

    public function beginDatabaseTransaction(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        IsolatedPostgresTestDatabase::assertSafeConfiguration(DB::connection()->getConfig());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/D', DB::connection()->getDatabaseName());
        $this->enableImmutableAuditWriter();
        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-proposal-race-'.bin2hex(random_bytes(10));
        self::assertTrue(mkdir($this->runtimeDirectory));
    }

    public function test_version_creation_and_approval_keep_the_same_current_version_in_both_orders(): void
    {
        foreach (['version', 'approval'] as $leadingOperation) {
            $context = AdminApiTestContext::create();
            $proposal = app(CommercialProposalService::class)->create((int) $context->organization->id, [
                'title' => 'Учебная конкурентная проверка',
                'customer_name' => 'Условный клиент',
                'sections' => [['title' => 'Состав', 'body' => 'Учебная позиция']],
                'line_items' => [['section_index' => 0, 'title' => 'Позиция', 'quantity' => '1', 'unit_price' => '100']],
            ], (int) $context->user->id);

            $results = $this->race($proposal, (int) $context->user->id, $leadingOperation);
            self::assertSame('completed', $results[0]['status'], json_encode($results));
            self::assertSame($leadingOperation === 'version' ? 'completed' : 'blocked', $results[1]['status'], json_encode($results));
            if ($leadingOperation === 'approval') {
                self::assertSame(['version_not_allowed'], $results[1]['blockers']);
            }

            $proposal->refresh();
            $approval = DB::table('commercial_proposal_approvals')->where('commercial_proposal_id', $proposal->id)->sole();
            self::assertSame('internal_review', $proposal->status->value);
            self::assertSame('pending', $approval->status);
            self::assertSame($proposal->current_version_id, $approval->commercial_proposal_version_id);
            $current = $proposal->currentVersion()->firstOrFail();
            self::assertSame('internal_review', $current->status);
            self::assertSame($leadingOperation === 'version' ? 2 : 1, $current->version_number);
            self::assertSame($leadingOperation === 'version' ? 2 : 1, $proposal->versions()->count());
        }
    }

    private function race(CommercialProposal $proposal, int $userId, string $leadingOperation): array
    {
        $connection = DB::connection()->getConfig();
        $environment = [
            'MOST_POSTGRES_TEST_PROFILE' => (string) (getenv('MOST_POSTGRES_TEST_PROFILE') ?: ''),
            'MOST_POSTGRES_TEST_PORT' => (string) (getenv('MOST_POSTGRES_TEST_PORT') ?: ''),
            'MOST_POSTGRES_TEST_PROJECT' => (string) (getenv('MOST_POSTGRES_TEST_PROJECT') ?: ''),
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'APP_CONFIG_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'no-config-cache',
            'ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP' => '1', 'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'],
            'DB_PASSWORD' => (string) $connection['password'], 'DB_SCHEMA' => (string) ($connection['search_path'] ?? 'public'), 'DB_URL' => '',
        ];
        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH'] as $key) {
            if (is_string(getenv($key))) { $environment[$key] = getenv($key); }
        }
        $raceName = 'proposal-race-'.bin2hex(random_bytes(8));
        $paths = [];
        $processes = [];
        $deadline = microtime(true) + 30;
        try {
            foreach ([$leadingOperation, $leadingOperation === 'version' ? 'approval' : 'version'] as $index => $operation) {
                $path = $this->runtimeDirectory.DIRECTORY_SEPARATOR.$raceName.'-'.$index.'.json';
                file_put_contents($path, json_encode([
                    'connection' => $connection, 'connection_name' => DB::getDefaultConnection(), 'key' => (string) config('app.key'),
                    'audit_secret' => (string) config('legal_archive.audit_writer_secret'),
                    'organization_id' => $proposal->organization_id, 'proposal_id' => $proposal->id, 'user_id' => $userId,
                    'operation' => $operation, 'pause' => $index === 0, 'race_name' => $raceName.'-'.$index,
                ], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/commercial-proposal-concurrency-worker.php'), $path], [
                    0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                    1 => ['file', $path.'.stdout', 'w'], 2 => ['file', $path.'.stderr', 'w'],
                ], $pipes, base_path(), $environment, ['bypass_shell' => true]);
                self::assertIsResource($process);
                $processes[] = $process;
                $paths[] = $path;
                $this->await(fn (): bool => is_file($path.'.ready') || is_file($path.'.result'), $deadline);
                if ($index === 0) {
                    self::assertFileDoesNotExist($path.'.result', is_file($path.'.result') ? (string) file_get_contents($path.'.result') : '');
                }
            }
            $this->await(function () use ($paths, $raceName): bool {
                if (is_file($paths[1].'.result')) { return true; }
                DB::select('SELECT pg_stat_clear_snapshot()');
                return DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())
                    ->where('application_name', $raceName.'-1')->where('wait_event_type', 'Lock')->exists();
            }, $deadline);
            file_put_contents($paths[0].'.release', 'release');
            $this->await(fn (): bool => is_file($paths[0].'.result') && is_file($paths[1].'.result'), $deadline, function () use ($paths): string {
                $diagnostics = [];
                foreach ($paths as $path) {
                    foreach (['.result', '.phase', '.sql', '.stderr'] as $suffix) {
                        $diagnostics[basename($path).$suffix] = is_file($path.$suffix) ? substr((string) file_get_contents($path.$suffix), -2500) : 'missing';
                    }
                }
                return json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            });
            return array_map(static fn (string $path): array => json_decode((string) file_get_contents($path.'.result'), true, 64, JSON_THROW_ON_ERROR), $paths);
        } finally {
            foreach ($paths as $path) { file_put_contents($path.'.release', 'release'); }
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
        }
    }

    private function await(callable $condition, float $deadline, ?callable $diagnostics = null): void
    {
        while (!$condition()) {
            if (microtime(true) >= $deadline) { self::fail('Commercial proposal concurrency worker timed out. '.($diagnostics !== null ? $diagnostics() : '')); }
            usleep(20_000);
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        if (isset($this->runtimeDirectory) && is_dir($this->runtimeDirectory)) {
            foreach (glob($this->runtimeDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) { unlink($path); }
            rmdir($this->runtimeDirectory);
        }
        parent::tearDown();
    }
}
