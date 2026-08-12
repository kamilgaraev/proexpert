<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaim;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitClaimStatus;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\EloquentDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1') {
    exit(64);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

$payload = json_decode((string) getenv('ESTIMATE_UNIT_FAILURE_CLAIM'), true, 16, JSON_THROW_ON_ERROR);
if (! is_array($payload)) {
    exit(65);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$claim = new DocumentProcessingUnitClaim(
    unitId: (int) $payload['unit_id'],
    status: DocumentProcessingUnitClaimStatus::Acquired,
    token: (string) $payload['token'],
    organizationId: (int) $payload['organization_id'],
    projectId: (int) $payload['project_id'],
    sessionId: (int) $payload['session_id'],
    documentId: (int) $payload['document_id'],
    sourceVersion: (string) $payload['source_version'],
);
$persisted = (new EloquentDocumentProcessingUnitStore(DB::connection()))->fail(
    $claim,
    'document_representation_contract_invalid',
    (string) $payload['fingerprint'],
    new DateTimeImmutable((string) $payload['failed_at']),
    FailureCategory::Terminal,
    true,
);

exit($persisted ? 0 : 2);
