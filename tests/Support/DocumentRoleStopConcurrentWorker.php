<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\StopEstimateGenerationDocumentProcessing;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getConfig('host') !== '127.0.0.1'
    || (string) DB::connection()->getConfig('port') !== '55433'
    || ! str_ends_with((string) DB::getDatabaseName(), '_testing')) {
    throw new RuntimeException('document_role_stop_worker_environment_unsafe');
}
app(AssistantIndexingState::class)->beginMigration();
[$script, $sessionId, $documentId, $actorId, $sourceVersion, $version] = $argv;
$authorization = Mockery::mock(AuthorizationService::class);
$authorization->allows('canCurrent')->andReturnTrue();
$service = new StopEstimateGenerationDocumentProcessing(
    $authorization,
    app(DocumentGenerationReadinessService::class),
    app(DocumentUnitAggregateReconciler::class),
);
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
fwrite(STDOUT, 'READY '.$pid."\n");
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('document_role_stop_coordination_failed');
}
$result = $service->handle(
    EstimateGenerationSession::query()->findOrFail((int) $sessionId),
    EstimateGenerationDocument::query()->findOrFail((int) $documentId),
    User::query()->findOrFail((int) $actorId),
    (int) $version,
    $sourceVersion,
    'role-stop-concurrent-'.$documentId,
);
fwrite(STDOUT, 'RESULT '.$result->disposition."\n");
Mockery::close();
