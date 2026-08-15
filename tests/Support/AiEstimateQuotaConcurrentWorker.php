<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Services\Billing\AiEstimateQuotaService;
use App\Exceptions\Billing\CommercialQuotaExceededException;
use App\Services\Billing\CommercialQuotaService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Mockery;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $organizationId, $sessionId] = $argv;
config()->set('commercial_limits.ai_estimates.included_monthly', 1);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
fgets(STDIN);

try {
    $commercialQuota = Mockery::mock(CommercialQuotaService::class);
    $commercialQuota->shouldReceive('getEffectiveAiEstimateMonthlyLimits')
        ->andReturn([(int) $organizationId => 1]);
    (new AiEstimateQuotaService(DB::connection(), $commercialQuota))
        ->reserveSession($organizationId, $sessionId);
    fwrite(STDOUT, "RESULT reserved\n");
} catch (CommercialQuotaExceededException) {
    fwrite(STDOUT, "RESULT quota_exceeded\n");
} finally {
    Mockery::close();
}
