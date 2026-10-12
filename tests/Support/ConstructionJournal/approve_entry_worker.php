<?php

declare(strict_types=1);

use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ConstructionJournalEntry;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

if (getenv('APP_ENV') !== 'testing' || ! preg_match('/^most_phpunit_[a-z0-9_]+_testing$/', (string) getenv('DB_DATABASE'))) {
    throw new RuntimeException('Isolated PostgreSQL test database required');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Notification::fake();
Queue::fake();
$authorization = Mockery::mock(AuthorizationService::class);
$authorization->shouldReceive('can')->andReturnTrue();
$authorization->shouldReceive('hasRole')->andReturnFalse();
$app->instance(AuthorizationService::class, $authorization);
DB::statement("SET lock_timeout = '25s'");
DB::statement("SET statement_timeout = '30s'");
DB::select("SELECT set_config('application_name', ?, false)", [(string) getenv('MOST_RACE_NAME')]);
try {
    $entry = ConstructionJournalEntry::query()->findOrFail((int) $argv[1]);
    $actor = User::query()->findOrFail((int) $argv[2]);
    $service = app(JournalApprovalService::class);
    $entry = $service->submitForApproval($entry, $actor);
    $entry = $service->approve($entry, $actor);
    $result = ['success' => true, 'entry_id' => $entry->id];
} catch (Throwable $exception) {
    $result = ['success' => false, 'message' => $exception->getMessage()];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
