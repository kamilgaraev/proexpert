<?php

declare(strict_types=1);

use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Contract;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('APP_ENV') !== 'testing' || !preg_match('/^most_phpunit_[a-z0-9_]+_testing$/', (string) getenv('DB_DATABASE'))) {
    throw new RuntimeException('Isolated PostgreSQL test database required');
}
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DB::statement("SET lock_timeout = '20s'");
DB::statement("SET statement_timeout = '25s'");
DB::select("SELECT set_config('application_name', ?, false)", [(string) getenv('MOST_RACE_NAME')]);
try {
    $contract = Contract::findOrFail((int) $argv[1]);
    $document = app(PaymentDocumentService::class)->create([
        'organization_id' => $contract->organization_id,
        'contract_id' => $contract->id,
        'document_type' => 'invoice', 'invoice_type' => 'advance',
        'document_date' => '2026-09-19', 'amount' => '10.00', 'status' => 'draft',
    ]);
    $result = ['success' => true, 'id' => $document->id];
} catch (Throwable $exception) {
    $result = ['success' => false, 'message' => $exception->getMessage()];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
