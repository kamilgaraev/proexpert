<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\Repositories\SpecificationRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecificationRepositoryProjectFilterTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
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
            $table->softDeletes();
        });
        Schema::create('contract_specification', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id');
            $table->foreignId('specification_id');
            $table->timestamp('attached_at')->nullable();
            $table->boolean('is_active')->default(false);
        });
    }
}
