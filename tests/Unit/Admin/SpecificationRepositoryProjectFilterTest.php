<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\DTOs\SpecificationDTO;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use App\Repositories\SpecificationRepository;
use App\Services\Contract\SpecificationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IsolatedPostgresTestDatabase;
use Tests\TestCase;

class SpecificationRepositoryProjectFilterTest extends TestCase
{
    // Regression: ISSUE-083 — каталог спецификаций раскрывал записи других организаций
    // Found by /qa on 2026-08-29
    // Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
    /** @var array<string, mixed> */
    private array $originalConnectionConfiguration;

    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $connectionName = DB::getDefaultConnection();
        $this->originalConnectionConfiguration = config('database.connections.'.$connectionName);
        config()->set(
            'database.connections.'.$connectionName,
            IsolatedPostgresTestDatabase::configuration(),
        );
        DB::purge($connectionName);
        DB::connection($connectionName);

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $connectionName = DB::getDefaultConnection();
        DB::purge($connectionName);
        config()->set(
            'database.connections.'.$connectionName,
            $this->originalConnectionConfiguration,
        );
        DB::connection($connectionName);

        parent::tearDown();
    }

    public function test_paginate_by_project_returns_only_specifications_linked_to_project_contracts(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Org']);
        $contractorId = DB::table('contractors')->insertGetId(['organization_id' => $organizationId, 'name' => 'Contractor']);
        $firstProjectId = DB::table('projects')->insertGetId(['organization_id' => $organizationId, 'name' => 'First']);
        $secondProjectId = DB::table('projects')->insertGetId(['organization_id' => $organizationId, 'name' => 'Second']);

        $firstContractId = DB::table('contracts')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $firstProjectId,
            'contractor_id' => $contractorId,
            'number' => 'C-1',
            'date' => '2026-05-01',
        ]);
        $secondContractId = DB::table('contracts')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $secondProjectId,
            'contractor_id' => $contractorId,
            'number' => 'C-2',
            'date' => '2026-05-01',
        ]);

        $firstSpecificationId = $this->createSpecification('S-1', '2026-05-02');
        $secondSpecificationId = $this->createSpecification('S-2', '2026-05-03');

        DB::table('contract_specification')->insert([
            'contract_id' => $firstContractId,
            'specification_id' => $firstSpecificationId,
            'attached_at' => now(),
            'is_active' => true,
        ]);
        DB::table('contract_specification')->insert([
            'contract_id' => $secondContractId,
            'specification_id' => $secondSpecificationId,
            'attached_at' => now(),
            'is_active' => true,
        ]);

        $result = (new SpecificationRepository())->paginateByProject($firstProjectId, 15);

        $this->assertSame([$firstSpecificationId], $result->getCollection()->pluck('id')->all());
    }

    public function test_paginate_by_project_includes_multi_project_contract_links(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Org']);
        $contractorId = DB::table('contractors')->insertGetId(['organization_id' => $organizationId, 'name' => 'Contractor']);
        $projectId = DB::table('projects')->insertGetId(['organization_id' => $organizationId, 'name' => 'Project']);
        $contractId = DB::table('contracts')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => null,
            'contractor_id' => $contractorId,
            'number' => 'C-M',
            'date' => '2026-05-01',
            'is_multi_project' => true,
        ]);
        $specificationId = $this->createSpecification('S-M', '2026-05-04');

        DB::table('contract_project')->insert([
            'contract_id' => $contractId,
            'project_id' => $projectId,
        ]);
        DB::table('contract_specification')->insert([
            'contract_id' => $contractId,
            'specification_id' => $specificationId,
            'attached_at' => now(),
            'is_active' => true,
        ]);

        $result = (new SpecificationRepository())->paginateByProject($projectId, 15);

        $this->assertSame([$specificationId], $result->getCollection()->pluck('id')->all());
    }

    public function test_paginate_for_organization_excludes_foreign_and_unlinked_specifications(): void
    {
        $firstOrganizationId = DB::table('organizations')->insertGetId(['name' => 'First org']);
        $secondOrganizationId = DB::table('organizations')->insertGetId(['name' => 'Second org']);
        $firstContractId = $this->createContract($firstOrganizationId, 'C-OWN');
        $secondContractId = $this->createContract($secondOrganizationId, 'C-FOREIGN');
        $ownSpecificationId = $this->createSpecification('S-OWN', '2026-05-05');
        $foreignSpecificationId = $this->createSpecification('S-FOREIGN', '2026-05-06');
        $this->createSpecification('S-UNLINKED', '2026-05-07');

        $this->attach($firstContractId, $ownSpecificationId);
        $this->attach($secondContractId, $foreignSpecificationId);

        $result = (new SpecificationRepository())->paginateForOrganization($firstOrganizationId, 15);

        $this->assertSame([$ownSpecificationId], $result->getCollection()->pluck('id')->all());
        $this->assertSame(['C-OWN'], $result->first()->contracts->pluck('number')->all());

        $payload = (new SpecificationResource($result->first()))->resolve(request());
        $this->assertSame('C-OWN', $payload['contracts'][0]['number']);
        $this->assertArrayNotHasKey('contract_id', $payload);
    }

    public function test_find_for_organization_rejects_foreign_and_unlinked_specifications(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Own org']);
        $foreignOrganizationId = DB::table('organizations')->insertGetId(['name' => 'Foreign org']);
        $ownContractId = $this->createContract($organizationId, 'C-OWN');
        $foreignContractId = $this->createContract($foreignOrganizationId, 'C-FOREIGN');
        $ownSpecificationId = $this->createSpecification('S-OWN', '2026-05-05');
        $foreignSpecificationId = $this->createSpecification('S-FOREIGN', '2026-05-06');
        $unlinkedSpecificationId = $this->createSpecification('S-UNLINKED', '2026-05-07');
        $sharedSpecificationId = $this->createSpecification('S-SHARED', '2026-05-08');

        $this->attach($ownContractId, $ownSpecificationId);
        $this->attach($foreignContractId, $foreignSpecificationId);
        $this->attach($ownContractId, $sharedSpecificationId);
        $this->attach($foreignContractId, $sharedSpecificationId);

        $repository = new SpecificationRepository();

        $this->assertSame($ownSpecificationId, $repository->findForOrganization($ownSpecificationId, $organizationId)?->id);
        $this->assertNull($repository->findForOrganization($foreignSpecificationId, $organizationId));
        $this->assertNull($repository->findForOrganization($unlinkedSpecificationId, $organizationId));
        $this->assertNull($repository->findForOrganization($sharedSpecificationId, $organizationId));
    }

    public function test_update_and_delete_are_limited_to_organization_specifications(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Own org']);
        $foreignOrganizationId = DB::table('organizations')->insertGetId(['name' => 'Foreign org']);
        $ownContractId = $this->createContract($organizationId, 'C-OWN');
        $foreignContractId = $this->createContract($foreignOrganizationId, 'C-FOREIGN');
        $ownSpecificationId = $this->createSpecification('S-OWN', '2026-05-05');
        $foreignSpecificationId = $this->createSpecification('S-FOREIGN', '2026-05-06');
        $this->attach($ownContractId, $ownSpecificationId);
        $this->attach($foreignContractId, $foreignSpecificationId);
        $repository = new SpecificationRepository();

        $updated = $repository->updateForOrganization(
            $ownSpecificationId,
            $organizationId,
            ['status' => 'archived'],
        );

        $this->assertSame('archived', $updated?->status);
        $this->assertNull($repository->updateForOrganization(
            $foreignSpecificationId,
            $organizationId,
            ['status' => 'archived'],
        ));
        $this->assertFalse($repository->deleteForOrganization($foreignSpecificationId, $organizationId));
        $this->assertDatabaseHas('specifications', [
            'id' => $foreignSpecificationId,
            'status' => 'approved',
            'deleted_at' => null,
        ]);
    }

    public function test_catalog_creation_atomically_attaches_specification_to_own_contract(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Own org']);
        $contractId = $this->createContract($organizationId, 'C-OWN');
        $previousSpecificationId = $this->createSpecification('S-PREVIOUS', '2026-05-04');
        $this->attach($contractId, $previousSpecificationId);
        $service = new SpecificationService(new SpecificationRepository());

        $created = $service->createForOrganization(
            new SpecificationDTO('S-NEW', '2026-05-08', 150, ['Работы'], 'approved'),
            $contractId,
            $organizationId,
        );

        $this->assertNotNull($created);
        $this->assertSame(['C-OWN'], $created->contracts->pluck('number')->all());
        $this->assertDatabaseHas('contract_specification', [
            'contract_id' => $contractId,
            'specification_id' => $created->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('contract_specification', [
            'contract_id' => $contractId,
            'specification_id' => $previousSpecificationId,
            'is_active' => false,
        ]);
    }

    public function test_catalog_creation_rejects_contract_from_another_organization(): void
    {
        $organizationId = DB::table('organizations')->insertGetId(['name' => 'Own org']);
        $foreignOrganizationId = DB::table('organizations')->insertGetId(['name' => 'Foreign org']);
        $foreignContractId = $this->createContract($foreignOrganizationId, 'C-FOREIGN');
        $service = new SpecificationService(new SpecificationRepository());

        $created = $service->createForOrganization(
            new SpecificationDTO('S-FORBIDDEN', '2026-05-08', 150, ['Работы'], 'approved'),
            $foreignContractId,
            $organizationId,
        );

        $this->assertNull($created);
        $this->assertDatabaseMissing('specifications', ['number' => 'S-FORBIDDEN']);
    }

    private function createSpecification(string $number, string $date): int
    {
        return DB::table('specifications')->insertGetId([
            'number' => $number,
            'spec_date' => $date,
            'total_amount' => 100,
            'scope_items' => json_encode([]),
            'status' => 'approved',
        ]);
    }

    private function createContract(int $organizationId, string $number): int
    {
        $contractorId = DB::table('contractors')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Contractor '.$number,
        ]);
        $projectId = DB::table('projects')->insertGetId([
            'organization_id' => $organizationId,
            'name' => 'Project '.$number,
        ]);

        return DB::table('contracts')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'contractor_id' => $contractorId,
            'number' => $number,
            'date' => '2026-05-01',
        ]);
    }

    private function attach(int $contractId, int $specificationId): void
    {
        DB::table('contract_specification')->insert([
            'contract_id' => $contractId,
            'specification_id' => $specificationId,
            'attached_at' => now(),
            'is_active' => true,
        ]);
    }

    private function createSchema(): void
    {
        foreach ([
            'contract_specification',
            'contract_project',
            'specifications',
            'contracts',
            'contractors',
            'projects',
            'organizations',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('contractors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->softDeletes();
        });
        Schema::create('contracts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id')->nullable();
            $table->foreignId('contractor_id');
            $table->string('number');
            $table->date('date');
            $table->boolean('is_multi_project')->default(false);
            $table->softDeletes();
        });
        Schema::create('contract_project', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->foreignId('project_id');
        });
        Schema::create('specifications', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->unique();
            $table->date('spec_date');
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->json('scope_items');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('contract_specification', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->foreignId('specification_id');
            $table->timestamp('attached_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }
}
