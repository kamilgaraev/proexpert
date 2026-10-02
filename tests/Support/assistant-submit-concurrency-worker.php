<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\IsolatedPostgresTestDatabase;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$payloadPath = $argv[1] ?? '';
if (realpath(dirname(dirname($payloadPath))) !== realpath(sys_get_temp_dir()) || !str_starts_with(basename(dirname($payloadPath)), 'most-assistant-submit-')) { exit(2); }
$payload = json_decode((string) file_get_contents($payloadPath), true, 64, JSON_THROW_ON_ERROR);
$connection = $payload['connection'];
try { IsolatedPostgresTestDatabase::assertSafeConfiguration($connection); }
catch (RuntimeException) { exit(2); }
if (getenv('APP_ENV') !== 'testing' || !preg_match('/^most_phpunit_[a-f0-9]+_testing$/D', $connection['database'] ?? '') || !preg_match('/^most_phpunit_[a-f0-9]+$/D', $connection['search_path'] ?? '') || ($payload['key'] ?? '') === '') { exit(2); }

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->useEnvironmentPath(dirname($payloadPath));
    $app->make(Kernel::class)->bootstrap();
    if (!$app->environment('testing')) { throw new RuntimeException('unsafe_child_environment'); }
    config()->set('database.connections.assistant_submit_load', $connection);
    DB::setDefaultConnection('assistant_submit_load');
    config()->set('app.key', $payload['key']);
    config()->set('ai-assistant-credits', $payload['policy']);
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    $authorization = Mockery::mock(AuthorizationService::class);
    $authorization->shouldReceive('canCurrent')->andReturn(true);
    $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
    $entitlements = Mockery::mock(OrganizationEntitlementService::class);
    $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'ai-assistant'])]));
    $app->instance(AssistantDataAccessPolicy::class, new AssistantDataAccessPolicy($authorization, Mockery::mock(UserProjectAccessService::class), $entitlements));
    Queue::fake();
    $actor = User::query()->findOrFail($payload['user_id']);
    $service = $app->make(QueuedAssistantChatService::class);
    file_put_contents($payloadPath.'.ready', 'ready');
    $deadline = microtime(true) + 90;
    while (!is_file($payload['barrier'])) {
        if (microtime(true) >= $deadline) { throw new RuntimeException('submit_barrier_timeout'); }
        usleep(10_000);
    }
    $startedAt = hrtime(true);
    $started = $service->submit($payload['organization_id'], $actor, null, $payload['request'], 'admin');
    $duration = (hrtime(true) - $startedAt) / 1000000;
    $replayed = $service->submit($payload['organization_id'], $actor, null, $payload['request'], 'admin');
    if (!$started['created'] || $replayed['created'] || $replayed['request']->id !== $started['request']->id) { throw new RuntimeException('submit_idempotency_failed'); }
    $result = ['status' => 'queued', 'jobs' => Queue::pushed(ExecuteAssistantChatJob::class)->count(), 'duration_ms' => round($duration, 2), 'peak_memory_bytes' => memory_get_peak_usage(true)];
} catch (Throwable $exception) {
    $result = ['status' => 'error', 'exception_class' => get_class($exception)];
}
file_put_contents($payloadPath.'.result', json_encode($result, JSON_THROW_ON_ERROR));
exit($result['status'] === 'queued' ? 0 : 1);
