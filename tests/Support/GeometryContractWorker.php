<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\ConfirmBuildingGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\EloquentGeometryRegenerationIntentStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\NoopGeometryConfirmationFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (($argv[1] ?? '') === 'confirm') {
        $data = json_decode(base64_decode($argv[2], true), true, 64, JSON_THROW_ON_ERROR);
        $hold = ($argv[3] ?? '0') === '1';
        $app->instance(GeometryConfirmationFaultInjector::class, $hold
            ? new class implements GeometryConfirmationFaultInjector
            {
                public function afterLocksAcquired(): void
                {
                    usleep(1_500_000);
                }

                public function afterInvalidation(): void {}
            }
            : new NoopGeometryConfirmationFaultInjector);
        $command = new GeometryConfirmationCommand(...$data);
        try {
            $app->make(ConfirmBuildingGeometry::class)->handle($command);
            fwrite(STDOUT, "winner\n");
        } catch (StaleEstimateGenerationState) {
            fwrite(STDOUT, "stale\n");
        }
    } elseif (($argv[1] ?? '') === 'outbox') {
        $probe = $argv[3];
        if (preg_match('/^geometry_dispatch_probe_[a-z0-9]{10}$/', $probe) !== 1) {
            throw new RuntimeException('geometry_contract_worker_probe_invalid');
        }
        $dispatcher = new class($probe) implements EstimateGenerationRetryDispatcher
        {
            public function __construct(private string $probe) {}

            public function dispatchDocuments(array $documentIds): void {}

            public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): bool
            {
                DB::selectOne('SELECT nextval(CAST(? AS regclass))', [$this->probe]);

                return true;
            }
        };
        $store = new EloquentGeometryRegenerationIntentStore(DB::connection(), $dispatcher);
        fwrite(STDOUT, $store->deliver((int) $argv[2]) ? "1\n" : "0\n");
    } else {
        throw new RuntimeException('geometry_contract_worker_action_invalid');
    }
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.':'.$exception->getMessage()."\n");
    exit(1);
}
