<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentMutationSessionReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ExplicitDocumentRetryEligibility;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\RetryEstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $sessionId, $documentId, $sourceVersion, $stateVersion, $idempotencyKey] = $argv;
$authorization = Mockery::mock(AuthorizationService::class);
$authorization->allows('can')->andReturnTrue();
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
);
$actor = new User;
$actor->forceFill(['id' => 7, 'current_organization_id' => 38]);
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
