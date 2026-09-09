<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Services\Contract\ContractTypeProfileQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class ContractTypeProfileQueryTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository(['legal-document-profiles' => [
            'contract.general' => ['label' => 'Договор подряда', 'category' => 'contract'],
            'act.general' => ['label' => 'Акт', 'category' => 'act'],
        ]]));
        $this->database = new Capsule($container);
        $this->database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->database->schema()->create('legal_archive_document_type_profiles', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->integer('organization_id');
            $table->string('code');
            $table->string('base_code');
            $table->string('name');
            $table->boolean('is_active');
            $table->jsonb('schema')->nullable();
        });
        $this->database->table('legal_archive_document_type_profiles')->insert([
            ['id' => 1, 'organization_id' => 77, 'code' => 'own', 'base_code' => 'contract.general', 'name' => 'Свой', 'is_active' => true],
            ['id' => 2, 'organization_id' => 78, 'code' => 'foreign', 'base_code' => 'contract.general', 'name' => 'Чужой', 'is_active' => true],
            ['id' => 3, 'organization_id' => 77, 'code' => 'inactive', 'base_code' => 'contract.general', 'name' => 'Архивный', 'is_active' => false],
            ['id' => 4, 'organization_id' => 77, 'code' => 'act', 'base_code' => 'act.general', 'name' => 'Акт', 'is_active' => true],
        ]);
    }

    public function test_only_active_contract_options_of_current_organization_are_returned(): void
    {
        $result = (new ContractTypeProfileQuery)->get(77, 1, 100);
        self::assertSame(['contract.general', 'own'], array_column($result['items'], 'code'));
        self::assertSame(2, $result['total']);
        self::assertSame(['code', 'name', 'category', 'is_active', 'is_standard'], array_keys($result['items'][1]));
    }

    public function test_pagination_crosses_standard_and_organization_options_without_duplicates(): void
    {
        $query = new ContractTypeProfileQuery;
        self::assertSame(['contract.general'], array_column($query->get(77, 1, 1)['items'], 'code'));
        self::assertSame(['own'], array_column($query->get(77, 2, 1)['items'], 'code'));
        self::assertSame([], $query->get(77, 3, 1)['items']);
    }

    public function test_missing_organization_context_is_denied(): void
    {
        $this->expectException(AuthorizationException::class);
        (new ContractTypeProfileQuery)->get(0, 1, 100);
    }

    public function test_route_uses_contract_creation_permission_and_does_not_change_archive_permission(): void
    {
        $container = Container::getInstance();
        $router = new Router(new Dispatcher($container), $container);
        $container->instance('router', $router);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstances();
        require __DIR__.'/../../../routes/api/v1/admin/contracts.php';
        require __DIR__.'/../../../routes/api/v1/admin/legal_archive.php';
        $route = $router->getRoutes()->match(Request::create('/contracts/type-profiles'));
        self::assertSame('contracts.type-profiles', $route->getName());
        self::assertContains('authorize:contracts.create', $route->gatherMiddleware());
        $archive = $router->getRoutes()->match(Request::create('/legal-archive/type-profiles'));
        self::assertContains('authorize:legal_archive.view', $archive->middleware());
    }

    protected function tearDown(): void
    {
        $this->database->schema()->dropIfExists('legal_archive_document_type_profiles');
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }
}
