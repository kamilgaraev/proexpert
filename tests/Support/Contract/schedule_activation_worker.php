<?php

declare(strict_types=1);

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Contract\ContractRevisionActivationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || !preg_match('/^most_phpunit_[a-z0-9_]+_testing$/', (string) getenv('DB_DATABASE'))) {
    throw new RuntimeException('Isolated PostgreSQL test database required');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET lock_timeout = '20s'");
DB::statement("SET statement_timeout = '25s'");
DB::select("SELECT set_config('application_name', ?, false)", [(string) getenv('MOST_RACE_NAME')]);
$authorization = Mockery::mock(AuthorizationService::class);
$authorization->shouldReceive('can')->andReturn(true);
$app->instance(AuthorizationService::class, $authorization);
try {
    $actor = User::findOrFail((int) $argv[1]);
    $activation = app(ContractRevisionActivationService::class)->schedule($actor, (int) $actor->current_organization_id,
        (int) $argv[2], 1, $argv[3], null, now()->addDay()->toDateString(), 'Подписанный договор', $argv[4]);
    $result = ['success' => true, 'activation_id' => $activation['id']];
} catch (Throwable $exception) {
    $result = ['success' => false, 'code' => $exception->getCode(), 'type' => $exception::class];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
