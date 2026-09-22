<?php

declare(strict_types=1);

use App\Models\Contract;
use App\Services\Acting\ActingActWizardService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || !preg_match('/^most_phpunit_[a-z0-9_]+_testing$/', (string) getenv('DB_DATABASE'))) {
    throw new RuntimeException('Isolated PostgreSQL test database required');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET lock_timeout = '25s'");
DB::statement("SET statement_timeout = '30s'");
DB::select("SELECT set_config('application_name', ?, false)", [(string) getenv('MOST_RACE_NAME')]);
try {
    $contract = Contract::query()->findOrFail((int) $argv[1]);
    $act = app(ActingActWizardService::class)->createFromWizard((int) $contract->organization_id, [
        'contract_id' => $contract->id,
        'act_document_number' => 'RACE-'.$argv[3],
        'act_date' => '2026-09-20', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
        'selected_works' => [['completed_work_id' => (int) $argv[2], 'quantity' => '7']],
    ], (int) $argv[4], false);
    $result = ['success' => true, 'id' => $act->id];
} catch (Throwable $exception) {
    $result = ['success' => false, 'code' => $exception->getCode(), 'message' => $exception->getMessage()];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
