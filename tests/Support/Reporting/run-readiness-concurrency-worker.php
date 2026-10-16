<?php

declare(strict_types=1);

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportRunStore;
use App\Support\Reporting\StableReportingSourceView;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$payloadPath = $argv[1] ?? '';
if (realpath(dirname(dirname($payloadPath))) !== realpath(sys_get_temp_dir())
    || !str_starts_with(basename(dirname($payloadPath)), 'most-report-run-race-')) {
    exit(2);
}
$payload = json_decode((string) file_get_contents($payloadPath), true, 64, JSON_THROW_ON_ERROR);
$connection = $payload['connection'];
try {
    IsolatedPostgresTestDatabase::assertSafeConfiguration($connection);
} catch (RuntimeException) {
    exit(2);
}
if (getenv('APP_ENV') !== 'testing'
    || preg_match('/^most_phpunit_[a-f0-9]+_testing$/D', $connection['database'] ?? '') !== 1
    || (($connection['search_path'] ?? '') !== 'public'
        && preg_match('/^most_phpunit_[a-f0-9]+$/D', $connection['search_path'] ?? '') !== 1)) {
    exit(2);
}

try {
    $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
    $app->useEnvironmentPath(dirname($payloadPath));
    $app->make(Kernel::class)->bootstrap();
    config()->set('database.connections.report_run_race', $connection);
    DB::setDefaultConnection('report_run_race');
    if (! $app->environment('testing')) {
        throw new RuntimeException('unsafe_child_environment');
    }
    DB::select("SELECT set_config('application_name', ?, false)", [$payload['race_name']]);
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    $organizationId = (int) $payload['organization_id'];
    $timezone = new DateTimeZone('UTC');
    $scope = new ReportScope($organizationId, [$organizationId], [], [], $timezone);
    $context = (new ReportExecutionContextBuilder)
        ->actor(new ReportActor((int) $payload['actor_id'], 'active', ['reports.view', 'reports.run']))
        ->scope($scope)
        ->authorization(new AuthorizationDecisionContext('http', $organizationId, [$organizationId], [], [], $timezone, 'readiness-race', null))
        ->build();
    $query = new ReportQuery(
        (new ReportDefinitionBuilder)->code('cost_control')->payload(), $scope,
        new ReportFilterSet(['period' => $payload['period']]), [], new DateTimeImmutable('2026-08-09T00:00:00Z'), 'ru',
    );
    if ($payload['outer_transaction']) {
        DB::beginTransaction();
    }
    $run = app(EloquentReportRunStore::class)->createOrReuse(
        $context, $query, null, new IdempotencyKey($payload['idempotency_key']),
        static function () use ($payload, $payloadPath, $organizationId): void {
            file_put_contents($payloadPath.'.inspected', (string) DB::transactionLevel());
            if ($payload['unavailable']) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            if (! $payload['outer_transaction']) {
                (new StableReportingSourceView)->capture(
                    static fn (): bool => DB::table('organizations')->where('id', $organizationId)->exists(),
                );
            }
        },
    );
    if ($payload['outer_transaction']) {
        DB::commit();
    }
    $result = ['status' => $run->httpDisposition, 'id' => $run->id];
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $result = ['status' => $exception instanceof ReportContractException ? $exception->errorCode->value : $exception::class];
}
file_put_contents($payloadPath.'.result', json_encode($result, JSON_THROW_ON_ERROR));
exit(0);
