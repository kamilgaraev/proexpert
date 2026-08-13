<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Settings\EloquentEffectiveSettingsOperationStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1') {
    exit(2);
}
$payload = json_decode((string) getenv('ESTIMATE_VISION_PIN_CONTRACT'), true, 16, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$pair = (new EloquentEffectiveSettingsOperationStore(DB::connection()))->pinVision(
    $payload['correlation_id'],
    $payload['organization_id'],
    $payload['session_id'],
    $payload['override'],
    'openai/gpt-5.6-luna',
);
fwrite(STDOUT, $pair->visionModel);
