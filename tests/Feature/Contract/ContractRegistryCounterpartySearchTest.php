<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Repositories\ContractRepository;
use App\Services\Logging\LoggingService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedPostgresTestDatabase;

final class ContractRegistryCounterpartySearchTest extends TestCase
{
    public function test_searches_saved_parties_and_suppliers_without_escaping_registry_filters(): void
    {
        $database = new Capsule;
        $database->addConnection(IsolatedPostgresTestDatabase::configuration());
        $database->setAsGlobal();
        $database->setEventDispatcher(new Dispatcher(new Container));
        $database->bootEloquent();
        Model::clearBootedModels();
        $container = $database->getContainer();
        $container->instance('db', $database->getDatabaseManager());
        $container->instance(LoggingService::class, Mockery::mock(LoggingService::class));
        $previousContainer = Container::getInstance();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        $schema = $database->schema();
        $schema->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('number');
            $table->date('date')->nullable();
            $table->string('subject')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('contract_parties', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->string('side');
            $table->string('name');
            $table->string('inn')->nullable();
        });
        foreach (['contractors', 'suppliers'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('inn')->nullable();
                $table->softDeletes();
            });
        }
        $schema->create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('external_code')->nullable();
            $table->softDeletes();
        });
        $schema->create('contract_project', static function (Blueprint $table): void {
            $table->unsignedBigInteger('contract_id');
            $table->unsignedBigInteger('project_id');
            $table->timestamps();
        });
        $schema->create('supplementary_agreements', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('contract_id');
            $table->decimal('change_amount')->default(0);
            $table->softDeletes();
        });
        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            $database->table('contracts')->insert([
                'id' => $id,
                'organization_id' => $id === 3 ? 99 : 7,
                'number' => 'Д-'.$id,
                'supplier_id' => $id === 6 ? 1 : null,
                'status' => $id === 4 ? 'active' : 'draft',
                'deleted_at' => $id === 5 ? '2026-09-01 12:00:00' : null,
            ]);
            if ($id !== 6) {
                $database->table('contract_parties')->insert([
                    'contract_id' => $id,
                    'side' => $id === 2 ? 'second' : 'first',
                    'name' => 'Контрагент Альфа',
                    'inn' => '1650000001',
                ]);
            }
        }
        $database->table('suppliers')->insert(['id' => 1, 'name' => 'Поставщик Бета', 'inn' => '1650000002']);
        $repository = new ContractRepository;
        try {
            $found = $repository->getContractsForOrganizationPaginated(7, 1, ['search' => 'кОнТрАгЕнТ аЛьФа', 'status' => 'draft'], 'number', 'asc');
            self::assertSame(2, $found->total());
            self::assertSame([1], $found->getCollection()->modelKeys());
            $byInn = $repository->getContractsForOrganizationPaginated(7, 15, ['search' => '1650000001', 'status' => 'draft'], 'number', 'asc');
            self::assertSame([1, 2], $byInn->getCollection()->modelKeys());
            $wrongProject = $repository->getContractsForOrganizationPaginated(7, 15, ['search' => 'Альфа', 'project_id' => 999]);
            self::assertSame(0, $wrongProject->total());
            foreach (['поставщик бета', '1650000002'] as $search) {
                $supplier = $repository->getContractsForOrganizationPaginated(7, 15, ['search' => $search]);
                self::assertSame([6], $supplier->getCollection()->modelKeys());
            }
            $number = $repository->getContractsForOrganizationPaginated(7, 15, ['search' => 'Д-6']);
            self::assertSame([6], $number->getCollection()->modelKeys());
        } finally {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
            Container::setInstance($previousContainer);
            Mockery::close();
        }
    }
}
