<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ResetDocumentProcessingUnitsForAttempt;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getConfig('host') !== '127.0.0.1'
    || (string) DB::connection()->getConfig('port') !== '55433'
    || ! str_ends_with((string) DB::getDatabaseName(), '_testing')) {
    throw new RuntimeException('explicit_retry_worker_environment_unsafe');
}
$indexingState = app(AssistantIndexingState::class);
$indexingState->beginMigration();

[$script, $sessionId, $documentId, $sourceVersion, $stateVersion, $idempotencyKey] = $argv;
$authorization = Mockery::mock(AuthorizationService::class);
$authorization->allows('canCurrent')->andReturnTrue();
$reconciler = Mockery::mock(DocumentMutationSessionReconciler::class);
$reconciler->allows('changed')->andReturnUsing(static fn (EstimateGenerationSession $session): EstimateGenerationSession => $session);
$readiness = Mockery::mock(DocumentGenerationReadinessService::class);
$readiness->allows('evaluate')->andReturn(['summary' => ['pending_count' => 1]]);
$service = new RetryEstimateGenerationDocument(
    new EstimateGenerationMutationPolicy,
    $reconciler,
    $readiness,
    $authorization,
    new ExplicitDocumentRetryEligibility,
    new ResetDocumentProcessingUnitsForAttempt,
);
$actor = User::query()->findOrFail(7);
Queue::fake();

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim((string) fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('Explicit retry race coordination failed.');
}
$result = $service->handle(
    EstimateGenerationSession::query()->findOrFail((int) $sessionId),
    EstimateGenerationDocument::query()->findOrFail((int) $documentId),
    $actor,
    (int) $stateVersion,
    $sourceVersion,
    $idempotencyKey,
    null,
);
$dispatches = Queue::pushed(ProcessEstimateGenerationDocumentJob::class)->count();
fwrite(STDOUT, 'RESULT '.json_encode([
    'disposition' => $result->disposition,
    'attempt_id' => $result->attemptId,
    'dispatches' => $dispatches,
], JSON_THROW_ON_ERROR)."\n");
Mockery::close();
$indexingState->endMigration();
