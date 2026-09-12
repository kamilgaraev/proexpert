<?php

declare(strict_types=1);

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\EstimateFinanceService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'pgsql'
    || preg_match('/^most_phpunit_[a-f0-9]{24}_testing$/D', (string) getenv('DB_DATABASE')) !== 1) {
    throw new RuntimeException('unsafe_finance_test_worker');
}
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$authorization = Mockery::mock(AuthorizationService::class);
$authorization->shouldReceive('can')->andReturn(true);
$app->instance(AuthorizationService::class, $authorization);
$actor = User::query()->findOrFail($input['actor']);
echo "READY\n";
flush();
try {
    $result = $app->make(EstimateFinanceService::class)->save($actor, $input['project'], $input['estimate'], $input['command']);
    echo json_encode(['status' => 200, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ConflictHttpException) {
    echo json_encode(['status' => 409], JSON_THROW_ON_ERROR);
}
