<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\Models\Organization;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;
use Tests\TestCase;

#[Group('postgres-contract')]
final class ReportRunReadinessConcurrencyTest extends TestCase
{
    #[DataProvider('requests')]
    public function test_concurrent_repeat_waits_for_commit_before_inspecting_readiness(bool $outerTransaction, bool $differentPayload): void
    {
        $organization = Organization::factory()->verified()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $suffix = bin2hex(random_bytes(6));
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-report-run-race-'.$suffix;
        self::assertTrue(mkdir($directory, 0700));
        $function = 'report_key_barrier_fn_'.$suffix;
        $trigger = 'report_key_barrier_trg_'.$suffix;
        $barrier = random_int(100000, 2000000000);
        $locked = false;
        $workers = [];
        $paths = [];
        $connection = DB::connection()->getConfig();
        $environment = [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
            'APP_CONFIG_CACHE' => $directory.DIRECTORY_SEPARATOR.'no-config-cache',
            'ESTIMATE_GENERATION_MODULAR_CONTRACT_BOOTSTRAP' => '0',
            'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => (string) $connection['host'],
            'DB_PORT' => (string) $connection['port'], 'DB_DATABASE' => (string) $connection['database'],
            'DB_USERNAME' => (string) $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'DB_SCHEMA' => (string) $connection['search_path'], 'DB_URL' => '',
        ];
        foreach (['MOST_POSTGRES_TEST_PROFILE', 'MOST_POSTGRES_TEST_PORT', 'MOST_POSTGRES_TEST_PROJECT', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'PATH'] as $name) {
            if (is_string(getenv($name))) {
                $environment[$name] = getenv($name);
            }
        }
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        try {
            DB::selectOne('SELECT pg_advisory_lock(?)', [$barrier]);
            $locked = true;
            DB::unprepared("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.organization_id = {$organization->id} THEN PERFORM pg_advisory_xact_lock({$barrier}); END IF; RETURN NEW; END $$");
            DB::unprepared("CREATE TRIGGER {$trigger} AFTER INSERT ON report_runs FOR EACH ROW EXECUTE FUNCTION {$function}()");
            foreach ([0, 1] as $index) {
                $path = $directory.DIRECTORY_SEPARATOR.'worker-'.$index.'.json';
                $paths[] = $path;
                $raceName = 'report-readiness-'.$suffix.'-'.$index;
                file_put_contents($path, json_encode([
                    'connection' => $connection, 'organization_id' => $organization->id, 'actor_id' => $actor->id,
                    'idempotency_key' => 'readiness-race-'.$suffix, 'race_name' => $raceName,
                    'period' => $index === 1 && $differentPayload ? 'week' : 'month',
                    'outer_transaction' => $outerTransaction, 'unavailable' => $index === 1,
                ], JSON_THROW_ON_ERROR));
                $worker = new Process([PHP_BINARY, base_path('tests/Support/Reporting/run-readiness-concurrency-worker.php'), $path], base_path(), $environment, timeout: 35);
                $worker->start();
                $workers[] = $worker;
                $this->awaitLock($worker, $raceName, $path, microtime(true) + 20);
                if ($index === 1) {
                    self::assertFileDoesNotExist($path.'.inspected');
                }
            }
            DB::selectOne('SELECT pg_advisory_unlock(?)', [$barrier]);
            $locked = false;
            $results = [];
            foreach ($workers as $index => $worker) {
                self::assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $results[] = json_decode((string) file_get_contents($paths[$index].'.result'), true, 32, JSON_THROW_ON_ERROR);
            }
            self::assertSame('created', $results[0]['status']);
            self::assertSame($differentPayload ? ReportErrorCode::REPORT_IDEMPOTENCY_CONFLICT->value : 'reused', $results[1]['status']);
            if (! $differentPayload) {
                self::assertSame($results[0]['id'], $results[1]['id']);
            }
            self::assertSame($outerTransaction ? '1' : '0', file_get_contents($paths[0].'.inspected'));
            self::assertFileDoesNotExist($paths[1].'.inspected');
            self::assertSame(1, DB::table('report_runs')->where('organization_id', $organization->id)->count());
            self::assertSame(1, DB::table('report_audit_intents')->where('organization_id', $organization->id)->count());
            self::assertSame(1, DB::table('report_dispatch_intents')->where('organization_id', $organization->id)->count());
        } finally {
            if ($locked) {
                DB::selectOne('SELECT pg_advisory_unlock(?)', [$barrier]);
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger} ON report_runs");
            DB::unprepared("DROP FUNCTION IF EXISTS {$function}()");
            foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($directory);
            DB::beginTransaction();
        }
    }

    public static function requests(): array
    {
        return ['direct_repeat' => [false, false], 'direct_conflict' => [false, true], 'outer_repeat' => [true, false], 'outer_conflict' => [true, true]];
    }

    public function test_failed_readiness_releases_the_session_key_lock(): void
    {
        $organization = Organization::factory()->verified()->create();
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $organizationId = (int) $organization->id;
        $timezone = new DateTimeZone('UTC');
        $scope = new ReportScope($organizationId, [$organizationId], [], [], $timezone);
        $context = (new ReportExecutionContextBuilder)
            ->actor(new ReportActor((int) $actor->id, 'active', ['reports.view', 'reports.run']))
            ->scope($scope)
            ->authorization(new AuthorizationDecisionContext('http', $organizationId, [$organizationId], [], [], $timezone, 'readiness-release', null))
            ->build();
        $query = new ReportQuery((new ReportDefinitionBuilder)->code('cost_control')->payload(), $scope, new ReportFilterSet([]), [], new DateTimeImmutable('2026-08-09T00:00:00Z'), 'ru');
        $key = new IdempotencyKey('readiness-release-'.bin2hex(random_bytes(6)));
        self::assertSame(1, DB::transactionLevel());
        DB::commit();
        try {
            try {
                app(EloquentReportRunStore::class)->createOrReuse($context, $query, null, $key, static function (): void {
                    throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
                });
                self::fail('Missing source evidence must reject the request.');
            } catch (ReportContractException $exception) {
                self::assertSame(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE, $exception->errorCode);
            }
            $observerName = 'report_key_observer_'.bin2hex(random_bytes(6));
            config()->set('database.connections.'.$observerName, DB::connection()->getConfig());
            $observer = DB::connection($observerName);
            $lockKey = 'reports:run:'.$organizationId.':'.$key->hash;
            try {
                $lock = $observer->selectOne('SELECT CASE WHEN pg_try_advisory_lock(hashtextextended(?, 0)) THEN 1 ELSE 0 END AS acquired', [$lockKey]);
                self::assertSame(1, (int) $lock->acquired);
                self::assertSame(0, DB::table('report_runs')->where('organization_id', $organizationId)->count());
            } finally {
                $observer->selectOne('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$lockKey]);
                DB::purge($observerName);
            }
        } finally {
            DB::beginTransaction();
        }
    }

    private function awaitLock(Process $worker, string $raceName, string $path, float $deadline): void
    {
        while (microtime(true) < $deadline) {
            DB::select('SELECT pg_stat_clear_snapshot()');
            if (DB::table('pg_stat_activity')->where('datname', DB::connection()->getDatabaseName())
                ->where('application_name', $raceName)->where('wait_event', 'advisory')->exists()) {
                return;
            }
            if (! $worker->isRunning()) {
                self::fail(is_file($path.'.result') ? (string) file_get_contents($path.'.result') : $worker->getErrorOutput());
            }
            usleep(20_000);
        }
        self::fail('Report run readiness worker did not wait on its advisory lock.');
    }
}
