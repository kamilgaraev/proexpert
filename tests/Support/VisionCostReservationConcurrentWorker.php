<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getConfig('host') !== '127.0.0.1'
    || (string) DB::connection()->getConfig('port') !== '55433'
    || ! str_ends_with((string) DB::getDatabaseName(), '_testing')) {
    throw new RuntimeException('vision_cost_worker_environment_unsafe');
}
$permissions = Mockery::mock(AuthorizationService::class);
$permissions->allows('canCurrent')->andReturnTrue();
$app->instance(EstimateGenerationActionAuthorization::class, new EstimateGenerationActionAuthorizer($permissions));

[$script, $attemptId, $fingerprint, $owner, $reservation] = $argv;

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);

$now = now()->toDateTimeImmutable();
try {
    app(VisionPhysicalAttemptStore::class)->markWireStarted(
        $attemptId,
        $fingerprint,
        $owner,
        $now,
        $now->modify('+180 seconds'),
        new AiCost($reservation, 'RUB', 'available'),
    );
    fwrite(STDOUT, "RESULT started\n");
} catch (DocumentUnitProcessingException $exception) {
    fwrite(STDOUT, 'RESULT '.$exception->safeCode."\n");
}
