<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AssistantConcurrentSubmitTest extends TestCase
{
    private string $originalConnection;
    private string $runtimeDirectory;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.assistant_submit_load', IsolatedPostgresTestDatabase::configuration());
        DB::setDefaultConnection('assistant_submit_load');
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->boolean('is_active')->default(true); $table->boolean('is_verified')->default(true); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password'); $table->boolean('is_active')->default(true); $table->unsignedBigInteger('current_organization_id')->nullable(); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id(); $table->foreignId('organization_id'); $table->foreignId('user_id'); $table->boolean('is_active')->default(true); $table->boolean('is_owner')->default(false); $table->timestamps();
        });
        Schema::create('commercial_orders', function (Blueprint $table): void { $table->id(); $table->string('kind')->default('purchase'); });
        Schema::create('ai_conversations', function (Blueprint $table): void { $table->id(); });
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        (require database_path('migrations/2026_09_29_000009_create_ai_assistant_requests_table.php'))->up();
        (require database_path('migrations/2026_09_29_000010_add_async_payload_to_ai_assistant_requests.php'))->up();
        config()->set('ai-assistant-credits.enforce', true);
        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-assistant-submit-'.bin2hex(random_bytes(10));
        self::assertTrue(mkdir($this->runtimeDirectory));
    }

    public static function parallelRequestCounts(): array
    {
        return ['one' => [1], 'five' => [5], 'twenty_five' => [25]];
    }

    #[DataProvider('parallelRequestCounts')]
    public function test_parallel_submit_preserves_requests_reservations_and_idempotency(int $count): void
    {
        $credits = new AICreditService;
        $organizations = [];
        $operations = [];
        for ($index = 0; $index < $count; $index++) {
            $group = intdiv($index, 5);
            $organization = $organizations[$group] ??= Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Организация '.($group + 1)]));
            $actor = User::withoutEvents(fn () => User::query()->create(['name' => 'Сотрудник '.$index, 'email' => 'submit-'.$index.'@example.test', 'password' => 'password', 'current_organization_id' => $organization->id]));
            DB::table('organization_user')->insert(['organization_id' => $organization->id, 'user_id' => $actor->id, 'is_active' => true, 'is_owner' => false]);
            $request = ['request_id' => (string) Str::uuid(), 'message' => 'Что у нас по бетону?', 'profile' => 'short', 'conversation_id' => null];
            $quote = $credits->quote($organization, $actor, $request);
            $operations[] = ['organization_id' => $organization->id, 'user_id' => $actor->id, 'request' => $request + ['quote_id' => $quote['quote_id']]];
        }
        foreach ($organizations as $organization) {
            $credits->grant($organization, 100000, 'purchase', null, 'submit-load-pack');
        }
        $results = $this->runConcurrently($operations);
        self::assertSame(array_fill(0, $count, 'queued'), array_column($results, 'status'));
        self::assertSame(array_fill(0, $count, 1), array_column($results, 'jobs'));
        self::assertSame($count, AssistantRequest::query()->where('stage', 'queued')->whereNull('started_at')->count());
        self::assertSame($count, AICreditReservation::query()->count());
        self::assertSame($count, AICreditLedgerEntry::query()->where('type', 'reserve')->count());
        self::assertSame(0, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        self::assertSame(0, AssistantRequest::query()->sum('calls_used'));
        $durations = array_column($results, 'duration_ms');
        sort($durations);
        $profile = ['requests' => $count, 'organizations' => count($organizations), 'median_ms' => $durations[(int) floor(($count - 1) / 2)], 'p95_ms' => $durations[max(0, (int) ceil($count * .95) - 1)], 'max_ms' => max($durations), 'max_peak_memory_bytes' => max(array_column($results, 'peak_memory_bytes')), 'provider_calls' => 0];
        $directory = storage_path('app/private/assistant-ci-monitor');
        if (!is_dir($directory)) { mkdir($directory, 0770, true); }
        file_put_contents($directory.'/submit-load-'.$count.'.json', json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        fwrite(STDOUT, PHP_EOL.'assistant_submit_load '.json_encode($profile, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function runConcurrently(array $operations): array
    {
        $connection = DB::connection()->getConfig();
        $environment = ['MOST_POSTGRES_TEST_PROFILE' => (string) (getenv('MOST_POSTGRES_TEST_PROFILE') ?: ''), 'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_CONFIG_CACHE' => $this->runtimeDirectory.'/no-config-cache', 'ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP' => '1', 'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'], 'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'], 'DB_SCHEMA' => (string) $connection['search_path'], 'DB_URL' => ''];
        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH'] as $key) {
            if (is_string(getenv($key))) { $environment[$key] = getenv($key); }
        }
        $barrier = $this->runtimeDirectory.'/start';
        $processes = [];
        $paths = [];
        $deadline = microtime(true) + 90;
        try {
            foreach ($operations as $index => $operation) {
                $path = $this->runtimeDirectory.'/worker-'.$index.'.json';
                file_put_contents($path, json_encode($operation + ['connection' => $connection, 'key' => (string) config('app.key'), 'policy' => config('ai-assistant-credits'), 'barrier' => $barrier], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/assistant-submit-concurrency-worker.php'), $path], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $path.'.stdout', 'w'], 2 => ['file', $path.'.stderr', 'w']], $pipes, base_path(), $environment, ['bypass_shell' => true]);
                self::assertIsResource($process);
                $processes[] = $process;
                $paths[] = $path;
            }
            $this->await(function () use ($paths): bool {
                foreach ($paths as $path) {
                    if (is_file($path.'.result') && !is_file($path.'.ready')) {
                        $result = json_decode((string) file_get_contents($path.'.result'), true, 64, JSON_THROW_ON_ERROR);
                        self::fail('Assistant worker initialization failed: '.($result['exception_class'] ?? 'unknown'));
                    }
                }
                return count(array_filter($paths, fn (string $path): bool => is_file($path.'.ready'))) === count($paths);
            }, $deadline);
            file_put_contents($barrier, 'start');
            $this->await(fn (): bool => count(array_filter($paths, fn (string $path): bool => is_file($path.'.result'))) === count($paths), $deadline);
            return array_map(fn (string $path): array => json_decode((string) file_get_contents($path.'.result'), true, 64, JSON_THROW_ON_ERROR), $paths);
        } finally {
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
        }
    }

    private function await(callable $condition, float $deadline): void
    {
        while (!$condition()) {
            if (microtime(true) >= $deadline) { self::fail('Assistant submit workers timed out.'); }
            usleep(20_000);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->runtimeDirectory) && is_dir($this->runtimeDirectory)) {
            foreach (glob($this->runtimeDirectory.'/*') ?: [] as $path) { unlink($path); }
            rmdir($this->runtimeDirectory);
        }
        if (isset($this->originalConnection)) {
            DB::setDefaultConnection($this->originalConnection);
            DB::purge('assistant_submit_load');
        }
        parent::tearDown();
    }
}
