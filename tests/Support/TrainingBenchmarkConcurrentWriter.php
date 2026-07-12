<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationTrainingDataset;
use App\BusinessModules\Addons\EstimateGeneration\Services\Training\EstimateGenerationTrainingDatasetService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $mode, $role, $datasetId, $organizationId, $key, $encodedManifest] = $argv;
$dataset = EstimateGenerationTrainingDataset::query()->findOrFail((int) $datasetId);
$manifest = json_decode(base64_decode($encodedManifest, true), true, 512, JSON_THROW_ON_ERROR);

if ($role === 'leader') {
    DB::beginTransaction();
    if ($mode === 'benchmark') {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$organizationId, $key]);
    } else {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?), hashtext(?))', [$organizationId, (string) $dataset->dataset_key]);
    }
    fwrite(STDOUT, "LOCKED\n");
    fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'CONTINUE') {
        throw new RuntimeException('coordination_failed');
    }
    DB::commit();
}

if ($mode === 'benchmark') {
    $result = $app->make(BenchmarkRunRepository::class)->start($dataset, $manifest, $key);
    fwrite(STDOUT, "DONE:{$result->uuid}\n");
} else {
    $result = $app->make(EstimateGenerationTrainingDatasetService::class)->appendVersion($dataset, null);
    fwrite(STDOUT, "DONE:{$result->version}\n");
}
