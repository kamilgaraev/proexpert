<?php

declare(strict_types=1);

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\PurchaseContractService;
use App\DTOs\Contract\ContractDTO;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractDossierCreationService;
use App\Services\Contract\ContractDossierDocumentCreator;
use App\Services\Contract\ContractSideMutationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $role] = array_pad($argv, 2, null);
$url = getenv('MOST_CONCURRENCY_TEST_DATABASE_URL');
$schema = getenv('MOST_CONCURRENCY_TEST_SCHEMA');
$orderId = filter_var(getenv('MOST_CONCURRENCY_TEST_ORDER_ID'), FILTER_VALIDATE_INT);
if (! in_array($role, ['leader', 'follower'], true) || ! is_string($url) || ! is_string($schema) || preg_match('/^procurement_contract_race_[a-f0-9]{12}$/D', $schema) !== 1 || ! $orderId) {
    exit(2);
}

$parts = parse_url($url);
if ($parts === false || ! in_array($parts['scheme'] ?? null, ['postgres', 'postgresql'], true)) {
    exit(2);
}
$container = new Container;
$database = new Capsule;
$database->addConnection(['driver' => 'pgsql', 'url' => $url, 'host' => $parts['host'] ?? '127.0.0.1', 'port' => $parts['port'] ?? 5432, 'database' => ltrim((string) ($parts['path'] ?? ''), '/'), 'username' => rawurldecode($parts['user'] ?? ''), 'password' => rawurldecode($parts['pass'] ?? ''), 'charset' => 'utf8', 'prefix' => ''], 'procurement_race_worker');
$database->setAsGlobal();
$database->setEventDispatcher(new Dispatcher($container));
$database->bootEloquent();
$database->getDatabaseManager()->setDefaultConnection('procurement_race_worker');
$connection = $database->getConnection('procurement_race_worker');
$transactions = new DatabaseTransactionsManager;
$connection->setTransactionManager($transactions);
$connection->statement("SET search_path TO {$schema}");
$connection->selectOne("SELECT set_config('application_name', ?, false)", ['most-procurement-contract-race-'.$role]);
$container->instance('db', $database->getDatabaseManager());
$container->instance('db.transactions', $transactions);
$container->instance('events', new Dispatcher($container));
Container::setInstance($container);
Facade::setFacadeApplication($container);

try {
    $audit = Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing();
    $mutations = new ContractAuditedMutationService($audit, $connection);
    $contracts = Mockery::mock(ContractSideMutationService::class);
    $contracts->shouldReceive('create')->once()->andReturnUsing(static function (int $organizationId, ContractDTO $dto, mixed $context = null, ?int $actorId = null, ?string $key = null): Contract {
        return Contract::query()->create(['organization_id' => $organizationId, 'supplier_id' => $dto->supplier_id, 'number' => $dto->number, 'status' => 'draft', 'dossier_creation_key' => $key]);
    });
    $documents = new PurchaseContractRaceDocumentCreator($role === 'leader');
    $dossiers = new ContractDossierCreationService($connection, $contracts, $mutations, $documents);
    $actor = new User;
    $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);
    Auth::shouldReceive('user')->once()->andReturn($actor);
    $service = Mockery::mock(PurchaseContractService::class, [$mutations, $dossiers])->makePartial();
    $service->shouldReceive('validateProcurementContractCreation')->once()->andReturnNull();
    $order = PurchaseOrder::query()->findOrFail($orderId);
    if ($role === 'follower') {
        fwrite(STDOUT, "started\n");
        fflush(STDOUT);
    }
    $contract = $service->createFromOrder($order);
    fwrite(STDOUT, json_encode(['contract_id' => (int) $contract->id], JSON_THROW_ON_ERROR)."\n");
    Mockery::close();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.':'.$exception->getMessage());
    Mockery::close();
    exit(1);
}

final class PurchaseContractRaceDocumentCreator implements ContractDossierDocumentCreator
{
    public function __construct(private readonly bool $pauseAfterOrderLock) {}

    public function create(int $organizationId, ?int $userId, array $data): LegalArchiveDocument
    {
        if ($this->pauseAfterOrderLock) {
            fwrite(STDOUT, "locked\n");
            fflush(STDOUT);
            if (trim((string) fgets(STDIN)) !== 'release') {
                throw new RuntimeException('invalid_race_release');
            }
        }
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 41, 'organization_id' => $organizationId]);

        return $document;
    }
}
