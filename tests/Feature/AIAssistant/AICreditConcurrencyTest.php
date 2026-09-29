<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\Models\Credits\AICreditLedgerEntry;
use App\Models\Credits\AICreditLot;
use App\Models\Credits\AICreditReservation;
use App\Models\Credits\AICreditWallet;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\AssistantCreditReadinessFixture;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

final class AICreditConcurrencyTest extends TestCase
{
    private AICreditService $credits;
    private Organization $organization;
    private User $user;
    private string $originalConnection;
    private string $runtimeDirectory;
    private ?string $approvalPath = null;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $connection = IsolatedPostgresTestDatabase::configuration();
        $this->assertSame('127.0.0.1', $connection['host']);
        $this->assertSame(IsolatedPostgresTestDatabase::profilePort(), (int) $connection['port']);
        $this->assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/D', $connection['database']);
        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.credit_race', $connection);
        DB::setDefaultConnection('credit_race');
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password'); $table->timestamps(); $table->softDeletes();
        });
        Schema::create('organization_user', function (Blueprint $table): void {
            $table->id(); $table->foreignId('organization_id'); $table->foreignId('user_id'); $table->boolean('is_active');
        });
        Schema::create('commercial_orders', function (Blueprint $table): void { $table->id(); $table->string('kind'); });
        (require database_path('migrations/2026_09_29_000006_create_ai_credit_tables.php'))->up();
        $this->organization = Organization::withoutEvents(fn () => Organization::query()->create(['name' => 'Гонка кредитов']));
        $this->user = User::withoutEvents(fn () => User::query()->create(['name' => 'Владелец', 'email' => 'credit-race@example.test', 'password' => 'password']));
        DB::table('organization_user')->insert(['organization_id' => $this->organization->id, 'user_id' => $this->user->id, 'is_active' => true]);
        $this->approvalPath = tempnam(sys_get_temp_dir(), 'most-credit-race-approval-');
        config()->set('ai-assistant-credits.readiness_approval_path', $this->approvalPath);
        $this->assertNotEmpty(config('app.key'));
        AssistantCreditReadinessFixture::write($this->approvalPath, (array) config('ai-assistant-credits'), (string) config('app.key'));
        config()->set('ai-assistant-credits.enforce', true);
        $this->runtimeDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-credit-race-'.bin2hex(random_bytes(10));
        $this->assertTrue(mkdir($this->runtimeDirectory));
        $this->credits = new AICreditService;
    }

    public function test_two_processes_cannot_reserve_more_than_one_quote_capacity(): void
    {
        $first = $this->beginPayload();
        $second = $this->beginPayload();
        $capacity = (int) $first['max_units_minor'];
        $this->assertSame($capacity, $second['max_units_minor']);
        $this->credits->grant($this->organization, $capacity, 'purchase', null, 'race-pack');
        $results = $this->race([$first, $second]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['insufficient', 'reserved'], $statuses);
        $this->assertSame(1, AICreditReservation::query()->count());
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'reserve')->count());
        $balance = $this->credits->balance($this->organization);
        $this->assertSame($capacity, $balance['total_minor']);
        $this->assertSame($capacity, $balance['reserved_minor']);
        $this->assertSame(0, $balance['available_minor']);
        $this->assertSame(0, (int) AICreditLot::query()->sum('remaining_minor'));
        $this->credits->cancel(AICreditReservation::query()->sole());
        $this->assertSame($capacity, $this->credits->balance($this->organization)['available_minor']);
        $this->assertSame(0, $this->credits->balance($this->organization)['reserved_minor']);
    }

    public function test_concurrent_finalization_and_cancellation_close_reservation_once(): void
    {
        $payload = $this->beginPayload();
        $capacity = (int) $payload['max_units_minor'];
        $this->credits->grant($this->organization, $capacity, 'purchase', null, 'race-pack');
        $reservation = $this->credits->begin($this->organization, $this->user, $payload['quote_id'], $payload['request']['request_id'], null, $payload['request']);
        $this->credits->recordProviderCost($reservation, 90001, 'timeweb', 'gpt-6-luna', 'completion', ['usage_key' => 'successful-call'], true);
        $expectedCharge = $this->credits->calculatedChargeMinor($reservation);
        $results = $this->race([
            ['operation' => 'finalize', 'reservation_id' => $reservation->id],
            ['operation' => 'cancel', 'reservation_id' => $reservation->id],
        ]);
        $this->assertSame(['completed', 'completed'], array_column($results, 'status'));
        $reservation->refresh();
        $this->assertContains($reservation->status, ['finalized', 'cancelled']);
        $charge = $reservation->status === 'finalized' ? $expectedCharge : 0;
        $this->assertSame($charge, (int) $reservation->consumed_minor);
        $this->assertSame($charge, $results[0]['charged_minor']);
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
        $this->assertSame(-$charge, (int) AICreditLedgerEntry::query()->where('type', 'consume')->sum('amount_minor'));
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'release')->count());
        $balance = $this->credits->balance($this->organization);
        $this->assertSame(0, $balance['reserved_minor']);
        $this->assertSame($capacity - $charge, $balance['available_minor']);
        $this->assertSame($balance['available_minor'], $balance['total_minor']);
        $this->assertSame($balance['total_minor'], (int) AICreditLot::query()->sum('remaining_minor'));
        $this->assertSame($charge, $this->credits->finalize($reservation));
        $this->credits->cancel($reservation);
        $this->assertSame(1, AICreditLedgerEntry::query()->where('type', 'consume')->count());
    }

    private function beginPayload(): array
    {
        $request = ['request_id' => (string) Str::uuid(), 'message' => 'Покажи баланс', 'profile' => 'short'];
        $quote = $this->credits->quote($this->organization, $this->user, $request);
        return ['operation' => 'begin', 'quote_id' => $quote['quote_id'], 'max_units_minor' => $quote['max_units_minor'], 'request' => $request];
    }

    private function race(array $operations): array
    {
        $raceName = 'credit-race-'.bin2hex(random_bytes(8));
        $barrier = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'start';
        $connection = DB::connection()->getConfig();
        $environment = ['MOST_POSTGRES_TEST_PROFILE' => (string) (getenv('MOST_POSTGRES_TEST_PROFILE') ?: ''),
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'APP_CONFIG_CACHE' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'no-config-cache',
            'ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP' => '1', 'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'], 'DB_SCHEMA' => (string) $connection['search_path'], 'DB_URL' => ''];
        foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH'] as $key) {
            if (is_string(getenv($key))) { $environment[$key] = getenv($key); }
        }
        $processes = [];
        $paths = [];
        $deadline = microtime(true) + 20;
        DB::beginTransaction();
        try {
            AICreditWallet::query()->where('organization_id', $this->organization->id)->lockForUpdate()->firstOrFail();
            foreach ($operations as $index => $operation) {
                $path = $this->runtimeDirectory.DIRECTORY_SEPARATOR.'worker-'.$index.'.json';
                file_put_contents($path, json_encode($operation + ['connection' => $connection, 'key' => (string) config('app.key'), 'policy' => config('ai-assistant-credits'), 'organization_id' => $this->organization->id, 'user_id' => $this->user->id, 'race_name' => $raceName, 'barrier' => $barrier], JSON_THROW_ON_ERROR));
                $process = proc_open([PHP_BINARY, base_path('tests/Support/ai-credit-concurrency-worker.php'), $path], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $path.'.stdout', 'w'], 2 => ['file', $path.'.stderr', 'w']], $pipes, base_path(), $environment, ['bypass_shell' => true]);
                $this->assertIsResource($process);
                $processes[] = $process;
                $paths[] = $path;
            }
            $this->await(fn (): bool => count(array_filter($paths, fn (string $path): bool => is_file($path.'.ready'))) === 2, $deadline);
            file_put_contents($barrier, 'start');
            $this->await(function () use ($raceName): bool {
                DB::select('SELECT pg_stat_clear_snapshot()');
                return DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())->where('application_name', $raceName)->where('wait_event_type', 'Lock')->count() === 2;
            }, $deadline);
            DB::commit();
            $this->await(fn (): bool => count(array_filter($paths, fn (string $path): bool => is_file($path.'.result'))) === 2, $deadline);
            $results = [];
            foreach ($paths as $path) {
                $results[] = json_decode((string) file_get_contents($path.'.result'), true, 64, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            if (DB::transactionLevel() > 0) { DB::rollBack(); }
            foreach ($processes as $process) {
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
        }
    }

    private function await(callable $condition, float $deadline): void
    {
        while (!$condition()) {
            if (microtime(true) >= $deadline) { $this->fail('Credit concurrency worker timed out.'); }
            usleep(20_000);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->runtimeDirectory) && is_dir($this->runtimeDirectory)) {
            foreach (glob($this->runtimeDirectory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) { unlink($path); }
            rmdir($this->runtimeDirectory);
        }
        if ($this->approvalPath !== null && is_file($this->approvalPath)) { unlink($this->approvalPath); }
        if (isset($this->originalConnection)) {
            DB::setDefaultConnection($this->originalConnection);
            DB::purge('credit_race');
        }
        parent::tearDown();
    }
}
