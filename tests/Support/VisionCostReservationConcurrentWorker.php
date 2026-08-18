<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCost;
use App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

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
