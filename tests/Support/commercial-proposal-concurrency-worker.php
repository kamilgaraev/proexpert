<?php

declare(strict_types=1);

use App\BusinessModules\Features\CommercialProposals\Exceptions\CommercialProposalWorkflowException;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalApproval;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposalVersion;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsolatedPostgresTestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$payloadPath = $argv[1] ?? '';
if (realpath(dirname(dirname($payloadPath))) !== realpath(sys_get_temp_dir())
    || !str_starts_with(basename(dirname($payloadPath)), 'most-proposal-race-')) {
    exit(2);
}
$payload = json_decode((string) file_get_contents($payloadPath), true, 64, JSON_THROW_ON_ERROR);
$connection = $payload['connection'];
try { IsolatedPostgresTestDatabase::assertSafeConfiguration($connection); }
catch (RuntimeException) { exit(2); }
if (getenv('APP_ENV') !== 'testing'
    || !preg_match('/^most_phpunit_[a-f0-9]+_testing$/D', $connection['database'] ?? '')
    || !preg_match('/^(?:public|most_phpunit_[a-f0-9]+)$/D', $connection['search_path'] ?? 'public')
    || ($payload['connection_name'] ?? '') !== 'pgsql'
    || ($payload['key'] ?? '') === '') {
    exit(2);
}

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(dirname($payloadPath));
    $app->make(Kernel::class)->bootstrap();
    config()->set('database.connections.'.$payload['connection_name'], $connection);
    DB::purge($payload['connection_name']);
    DB::setDefaultConnection($payload['connection_name']);
    config()->set('app.key', $payload['key']);
    config()->set('legal_archive.audit_writer_secret', $payload['audit_secret']);
    Queue::fake();
    DB::listen(static function ($query) use ($payloadPath): void {
        file_put_contents($payloadPath.'.sql', $query->connectionName.' '.$query->sql."\n", FILE_APPEND);
    });
    if (!$app->environment('testing')) { throw new RuntimeException('unsafe_child_environment'); }
    DB::select("SELECT set_config('application_name', ?, false)", [$payload['race_name']]);
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    file_put_contents($payloadPath.'.phase', 'booted '.microtime(true));
    $deadline = microtime(true) + 25;
    if ($payload['pause']) {
        $modelClass = $payload['operation'] === 'version' ? CommercialProposalVersion::class : CommercialProposalApproval::class;
        $modelClass::creating(static function () use ($payloadPath, $deadline): void {
            file_put_contents($payloadPath.'.phase', "\npaused ".microtime(true), FILE_APPEND);
            file_put_contents($payloadPath.'.ready', 'ready');
            while (!is_file($payloadPath.'.release')) {
                if (microtime(true) >= $deadline) { throw new RuntimeException('race_barrier_timeout'); }
                usleep(10_000);
            }
            file_put_contents($payloadPath.'.phase', "\nreleased ".microtime(true), FILE_APPEND);
        });
    } else {
        file_put_contents($payloadPath.'.ready', 'ready');
    }
    $service = $app->make(CommercialProposalService::class);
    if ($payload['operation'] === 'version') {
        $service->createVersion((int) $payload['organization_id'], $payload['proposal_id'], ['title' => 'Учебная версия 2'], (int) $payload['user_id']);
    } elseif ($payload['operation'] === 'approval') {
        $service->requestApproval((int) $payload['organization_id'], $payload['proposal_id'], [], (int) $payload['user_id']);
    } else {
        throw new RuntimeException('unknown_race_operation');
    }
    $result = ['status' => 'completed'];
} catch (CommercialProposalWorkflowException $exception) {
    $result = ['status' => 'blocked', 'blockers' => array_column($exception->blockers(), 'code')];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'exception_class' => get_class($exception)];
}
file_put_contents($payloadPath.'.phase', "\nfinished ".microtime(true), FILE_APPEND);
file_put_contents($payloadPath.'.result', json_encode($result, JSON_THROW_ON_ERROR));
exit($result['status'] === 'error' ? 1 : 0);
