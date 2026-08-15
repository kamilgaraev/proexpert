<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchCandidate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitDispatchStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $action, $documentId, $unitId, $sourceVersion] = $argv;

if ($action === 'cancel') {
    DB::transaction(function () use ($documentId, $sourceVersion): void {
        DB::table('estimate_generation_documents')
            ->where('id', (int) $documentId)
            ->where('source_version', $sourceVersion)
            ->lockForUpdate()
            ->firstOrFail();
        DB::table('estimate_generation_documents')->where('id', (int) $documentId)->update([
            'status' => 'needs_review',
            'processing_stage' => 'completed',
            'processing_control_status' => 'cancelled',
            'processing_control_reason' => 'operator_stop',
            'processing_control_at' => now(),
            'updated_at' => now(),
        ]);
        fwrite(STDOUT, "LOCKED\n");
        fflush(STDOUT);
        fgets(STDIN);
    });
    fwrite(STDOUT, "RESULT cancelled\n");
    exit(0);
}

fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);
fwrite(STDOUT, "ATTEMPT\n");
fflush(STDOUT);

if ($action === 'claim') {
    $now = now()->toDateTimeImmutable();
    $claim = app(DocumentProcessingUnitStore::class)->claim(
        (int) $unitId,
        $sourceVersion,
        $now,
        $now->modify('+180 seconds'),
        3,
    );
    fwrite(STDOUT, 'RESULT '.$claim->status->value."\n");
    exit(0);
}

$invoked = false;
$now = now()->toDateTimeImmutable();
$dispatched = app(DocumentUnitDispatchStore::class)->dispatchIfAllowed(
    new DocumentUnitDispatchCandidate((int) $unitId, $sourceVersion, false),
    $now,
    $now->modify('+300 seconds'),
    static function () use (&$invoked): void {
        $invoked = true;
    },
);
fwrite(STDOUT, sprintf("RESULT %s:%s\n", $dispatched ? 'dispatched' : 'blocked', $invoked ? 'invoked' : 'not_invoked'));
