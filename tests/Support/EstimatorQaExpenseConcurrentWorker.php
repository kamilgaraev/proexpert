<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostGuard;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SessionAiCostLimitReached;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TextAiWireAttempt;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getConfig('host') !== '127.0.0.1'
    || (string) DB::connection()->getConfig('port') !== '55433'
    || ! str_ends_with((string) DB::getDatabaseName(), '_testing')) {
    throw new RuntimeException('estimator_qa_worker_environment_unsafe');
}
[$script, $runId, $attemptId] = $argv;
config(['estimate-generation.live_qa.enabled' => true, 'estimate-generation.live_qa.run_id' => $runId,
    'estimate-generation.credits.enabled' => false, 'ai-assistant-credits.rub_per_unit' => '0.18',
    'ai-assistant-credits.unit_minor' => 100, 'ai-assistant-credits.price_version' => 2]);
$permissions = Mockery::mock(AuthorizationService::class);
$permissions->allows('canCurrent')->andReturnTrue();
$app->instance(EstimateGenerationActionAuthorization::class, new EstimateGenerationActionAuthorizer($permissions));
$product = Mockery::mock(OrganizationEntitlementService::class);
$product->allows('hasModuleAccess')->andReturnTrue();
$app->instance(OrganizationEntitlementService::class, $product);
$physical = DB::table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attemptId)->sole();
$actorId = DB::table('estimate_generation_sessions')->where('id', $physical->session_id)->value('user_id');
$actor = User::query()->findOrFail($actorId);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);
try {
    app(SessionAiCostGuard::class)->authorize((int) $physical->organization_id, (int) $physical->project_id, (int) $physical->session_id,
        new TextAiWireAttempt($attemptId, $physical->request_fingerprint, new AiCost('50', 'RUB', 'available'), 60,
            stateVersion: 0, trustedActor: $actor, permission: 'estimate_generation.review'));
    fwrite(STDOUT, "RESULT started\n");
} catch (SessionAiCostLimitReached $exception) {
    fwrite(STDOUT, 'RESULT '.$exception->reason."\n");
}
