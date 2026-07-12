<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkPrivateObjectStore;
use App\BusinessModules\Addons\EstimateGeneration\Benchmark\BenchmarkRunRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\SharedVersionedBenchmarkObjectStore;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
[$script, $role, $organizationId, $uuid, $path, $directory] = $argv;
$app->instance(BenchmarkPrivateObjectStore::class, new SharedVersionedBenchmarkObjectStore($directory));
if ($role === 'A') {
    DB::statement("SET application_name = 'benchmark_adoption_fail'");
    putenv('BENCHMARK_FAKE_PAUSE_AFTER_CREATE=1');
}
try {
    $run = $app->make(BenchmarkRunRepository::class)->complete((int) $organizationId, $uuid,
        ['technical_success_rate' => ['macro' => 1]], null, $path, 10, '0');
    fwrite(STDOUT, 'DONE:'.$run->status."\n");
} catch (Throwable $exception) {
    fwrite(STDOUT, 'FAILED:'.$exception->getMessage()."\n");
}
$locked = (bool) (DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS locked', [$organizationId.'|'.$uuid.'|'.basename($path, '.json')])->locked ?? false);
if ($locked) {
    DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$organizationId.'|'.$uuid.'|'.basename($path, '.json')]);
}
fwrite(STDOUT, 'LOCK_RELEASED:'.($locked ? 'yes' : 'no')."\n");
