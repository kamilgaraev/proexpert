<?php

declare(strict_types=1);

use App\Models\Credits\AICreditReservation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Credits\AICreditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedPostgresTestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$payloadPath = $argv[1] ?? '';
if (realpath(dirname(dirname($payloadPath))) !== realpath(sys_get_temp_dir()) || !str_starts_with(basename(dirname($payloadPath)), 'most-credit-race-')) {
    exit(2);
}
$payload = json_decode((string) file_get_contents($payloadPath), true, 64, JSON_THROW_ON_ERROR);
$connection = $payload['connection'];
try { IsolatedPostgresTestDatabase::assertSafeConfiguration($connection); }
catch (RuntimeException) { exit(2); }
if (getenv('APP_ENV') !== 'testing' || ($connection['driver'] ?? '') !== 'pgsql'
    || ($connection['host'] ?? '') !== '127.0.0.1' || (int) ($connection['port'] ?? 0) !== IsolatedPostgresTestDatabase::profilePort()
    || !preg_match('/^most_phpunit_[a-f0-9]+_testing$/D', $connection['database'] ?? '')
    || !preg_match('/^most_phpunit_[a-f0-9]+$/D', $connection['search_path'] ?? '')
    || ($payload['key'] ?? '') === '' || !empty($connection['url'])) {
    exit(2);
}

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(dirname($payloadPath));
    $app->make(Kernel::class)->bootstrap();
    config()->set('database.connections.credit_race', $connection);
    DB::setDefaultConnection('credit_race');
    config()->set('app.key', $payload['key']);
    config()->set('ai-assistant-credits', $payload['policy']);
    if (!$app->environment('testing')) { throw new RuntimeException('unsafe_child_environment'); }
    DB::select("SELECT set_config('application_name', ?, false)", [$payload['race_name']]);
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '18s'");
    file_put_contents($payloadPath.'.ready', 'ready');
    $deadline = microtime(true) + 20;
    while (!is_file($payload['barrier'])) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('race_barrier_timeout'); }
        usleep(10_000);
    }
    $credits = new AICreditService;
    if ($payload['operation'] === 'begin') {
        $reservation = $credits->begin(Organization::query()->findOrFail($payload['organization_id']), User::query()->findOrFail($payload['user_id']), $payload['quote_id'], $payload['request']['request_id'], null, $payload['request']);
        $result = ['status' => 'reserved', 'reservation_id' => $reservation->id];
    } elseif ($payload['operation'] === 'finalize') {
        $result = ['status' => 'completed', 'charged_minor' => $credits->finalize(AICreditReservation::query()->findOrFail($payload['reservation_id']))];
    } elseif ($payload['operation'] === 'cancel') {
        $credits->cancel(AICreditReservation::query()->findOrFail($payload['reservation_id']));
        $result = ['status' => 'completed'];
    } else {
        throw new RuntimeException('unknown_race_operation');
    }
} catch (DomainException $exception) {
    $result = ['status' => $exception->getMessage() === 'Insufficient AI credits.' ? 'insufficient' : 'unexpected_domain_error'];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'exception_class' => get_class($exception)];
}
file_put_contents($payloadPath.'.result', json_encode($result, JSON_THROW_ON_ERROR));
exit(in_array($result['status'], ['reserved', 'completed', 'insufficient'], true) ? 0 : 1);
