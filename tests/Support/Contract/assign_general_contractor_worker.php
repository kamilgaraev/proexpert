<?php

declare(strict_types=1);

use App\Enums\ProjectOrganizationRole;
use App\Models\Project;
use App\Services\Project\ProjectParticipantService;
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
try {
    app(ProjectParticipantService::class)->attach(
        Project::findOrFail((int) $argv[1]), (int) $argv[2],
        ProjectOrganizationRole::GENERAL_CONTRACTOR, confirmedCapabilities: true,
    );
    $result = ['success' => true];
} catch (Throwable $exception) {
    $result = ['success' => false, 'code' => $exception->getCode(), 'message' => $exception->getMessage()];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
