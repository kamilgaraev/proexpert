<?php

declare(strict_types=1);

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\ContractPaymentLockService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Services\Contract\ContractBuilderMutationGuard;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Config\Repository;
use Psr\Log\NullLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

require dirname(__DIR__, 3).'/vendor/autoload.php';

[$script, $role] = array_pad($argv, 2, null);
$schema = getenv('MOST_CONTRACT_PAYMENT_RACE_SCHEMA');
$documentId = filter_var(getenv('MOST_CONTRACT_PAYMENT_RACE_DOCUMENT_ID'), FILTER_VALIDATE_INT);
$database = getenv('MOST_CONTRACT_PAYMENT_RACE_DATABASE');
if (! in_array($role, ['payment', 'update'], true)
    || ! is_string($schema) || preg_match('/^contract_payment_race_[a-f0-9]{12}$/D', $schema) !== 1
    || ! $documentId || ! is_string($database)) {
    exit(2);
}

$container = new Application(dirname(__DIR__, 3));
$container->instance('app', $container);
$container->instance('config', new Repository(['app' => ['locale' => 'ru', 'fallback_locale' => 'ru']]));
$container->instance('translator', new Translator(new ArrayLoader, 'ru'));
$container->instance('log', new NullLogger);
$capsule = new Capsule;
$capsule->addConnection([
    'driver' => 'pgsql',
    'host' => getenv('MOST_CONTRACT_PAYMENT_RACE_HOST'),
    'port' => getenv('MOST_CONTRACT_PAYMENT_RACE_PORT'),
    'database' => $database,
    'username' => getenv('MOST_CONTRACT_PAYMENT_RACE_USERNAME'),
    'password' => getenv('MOST_CONTRACT_PAYMENT_RACE_PASSWORD'),
    'charset' => 'utf8',
    'prefix' => '',
], 'contract_payment_race_worker');
$capsule->setAsGlobal();
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->bootEloquent();
$manager = $capsule->getDatabaseManager();
$manager->setDefaultConnection('contract_payment_race_worker');
$container->instance('db', $manager);
$container->instance('events', new Dispatcher($container));
Container::setInstance($container);
Facade::setFacadeApplication($container);
$connection = $manager->connection('contract_payment_race_worker');
$connection->statement("SET search_path TO {$schema}");
$connection->selectOne('SELECT set_config(\'application_name\', ?, false)', ['most-contract-payment-race-'.$role]);

try {
    if ($role === 'payment') {
        $connection->beginTransaction();
        $document = PaymentDocument::withoutEvents(
            static fn (): PaymentDocument => PaymentDocument::query()->findOrFail($documentId)
        );
        Contract::withoutEvents(static fn () => (new ContractPaymentLockService)->lockForPaymentDocument($document));
        fwrite(STDOUT, "contract_locked\n");
        fflush(STDOUT);
        if (trim((string) fgets(STDIN)) !== 'release') {
            throw new RuntimeException('invalid_payment_release');
        }
        $connection->table('payment_transactions')->insert([
            'payment_document_id' => $documentId,
            'organization_id' => $document->organization_id,
            'amount' => '600.00',
            'status' => 'completed',
        ]);
        $connection->table('payment_documents')->where('id', $documentId)->update([
            'paid_amount' => '600.00',
            'remaining_amount' => '0.00',
            'status' => 'paid',
        ]);
        $connection->commit();
        fwrite(STDOUT, "payment_committed\n");
    } else {
        fwrite(STDOUT, "update_started\n");
        fflush(STDOUT);
        $connection->beginTransaction();
        $contract = Contract::withoutEvents(
            static fn (): Contract => Contract::query()->whereKey(100)->lockForUpdate()->firstOrFail()
        );
        (new ContractBuilderMutationGuard)->assertUpdate($contract, ['total_amount' => '500.00'], 'update');
        $connection->table('contracts')->where('id', 100)->update(['total_amount' => '500.00']);
        $connection->commit();
        fwrite(STDOUT, "update_committed\n");
    }
    exit(0);
} catch (ContractBuilderException $exception) {
    $connection->rollBack();
    fwrite(STDOUT, "financial_conflict\n");
    exit($exception->getCode() === 409 ? 0 : 1);
} catch (Throwable $exception) {
    if ($connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    fwrite(STDERR, $exception::class.':'.$exception->getMessage().' '.$exception->getTraceAsString());
    exit(1);
}
