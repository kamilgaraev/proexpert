<?php

declare(strict_types=1);

use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Models\Contract;
use App\Services\Contract\ContractSideMutationService;
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
    $dto = new ContractDTO(
        project_id: $contract->project_id, contractor_id: (int) $argv[2], parent_contract_id: null,
        number: $contract->number, date: '2026-09-19', subject: null, work_type_category: null,
        payment_terms: null, base_amount: 100, total_amount: 100, gp_percentage: null,
        gp_calculation_type: null, gp_coefficient: null, warranty_retention_calculation_type: null,
        warranty_retention_percentage: null, warranty_retention_coefficient: null, subcontract_amount: null,
        planned_advance_amount: null, actual_advance_amount: null, status: ContractStatusEnum::ACTIVE,
        start_date: null, end_date: null, notes: null, contract_side_type: ContractSideTypeEnum::CONTRACT,
    );
    app(ContractSideMutationService::class)->update($contract->id, $contract->organization_id, $dto);
    $result = ['success' => true];
} catch (Throwable $exception) {
    $result = ['success' => false, 'message' => $exception->getMessage()];
}
fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
