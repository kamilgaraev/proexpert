<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $sessionId, $packageId, $draftPayload, $mode] = $argv;
$draft = json_decode(base64_decode($draftPayload, true), true, 512, JSON_THROW_ON_ERROR);
$session = EstimateGenerationSession::query()->findOrFail((int) $sessionId);

if ($mode === 'leader') {
    DB::beginTransaction();
    DB::table('estimate_generation_packages')->where('id', (int) $packageId)->lockForUpdate()->first();
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'CONTINUE') {
        throw new RuntimeException('Concurrent writer coordination failed.');
    }
    app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $draft);
    DB::commit();
} else {
    app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($session, $draft);
}

fwrite(STDOUT, "DONE\n");
