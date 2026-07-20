<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\MultiOrganization;

use App\BusinessModules\Core\MultiOrganization\Http\Controllers\HoldingLegalArchiveController;
use App\BusinessModules\Core\MultiOrganization\Services\ContextAwareOrganizationScope;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class HoldingLegalArchiveControllerTest extends TestCase
{
    private string $originalConnection;

    private bool $downloadsAllowed = true;

    private bool $financialAllowed = true;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.holding_legal_archive_contract', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('holding_legal_archive_contract');
        DB::setDefaultConnection('holding_legal_archive_contract');
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('holding_legal_archive_contract');
        parent::tearDown();
    }

    public function test_holding_reads_a_child_dossier_but_cannot_probe_sibling_or_unrelated_organizations(): void
    {
        $this->seedHoldingScope();
        $this->seedDossier(101, 2, 201, 301);
        $this->seedContract(102, 3);
        $this->seedDossier(102, 3, 202, 302);
        $this->seedContract(103, 4);
        $this->seedDossier(103, 4, 203, 303);

        $controller = $this->controller([1, 2]);

        $child = $controller->show($this->request(), 101);
        self::assertSame(200, $child->getStatusCode());
        self::assertSame(201, $child->getData(true)['data']['id']);
        self::assertSame(2, $child->getData(true)['data']['organization']['id']);
        self::assertSame(101, $child->getData(true)['data']['contract']['id']);

        self::assertSame(404, $controller->show($this->request(), 102)->getStatusCode());
        self::assertSame(404, $controller->show($this->request(), 103)->getStatusCode());
    }

    public function test_financial_summary_and_files_are_masked_independently_when_permissions_are_missing(): void
    {
        $this->seedHoldingScope();
        $this->seedDossier(101, 2, 201, 301);
        DB::table('payment_documents')->insert([
            'id' => 1,
            'invoiceable_type' => Contract::class,
            'invoiceable_id' => 101,
            'paid_amount' => 350.0,
            'status' => 'paid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->downloadsAllowed = false;
        $this->financialAllowed = false;
        $masked = $this->controller([1, 2])->show($this->request(), 101);

        self::assertSame(200, $masked->getStatusCode());
        self::assertFalse($masked->getData(true)['data']['permissions']['can_preview_download']);
        self::assertSame([], $masked->getData(true)['data']['files']);
        self::assertSame([
            'visible' => false,
            'total_amount' => null,
            'paid_amount' => null,
            'remaining_amount' => null,
        ], $masked->getData(true)['data']['financial_summary']);

        $this->downloadsAllowed = true;
        $this->financialAllowed = true;
        $visible = $this->controller([1, 2])->show($this->request(), 101);

        self::assertSame(200, $visible->getStatusCode());
        self::assertTrue($visible->getData(true)['data']['permissions']['can_preview_download']);
        self::assertCount(1, $visible->getData(true)['data']['files']);
        self::assertSame(301, $visible->getData(true)['data']['files'][0]['current_version']['id']);
        self::assertSame([
            'visible' => true,
            'total_amount' => 1000.0,
            'paid_amount' => 350.0,
            'remaining_amount' => 650.0,
        ], $visible->getData(true)['data']['financial_summary']);
    }

    public function test_non_holding_context_is_indistinguishable_from_a_missing_dossier(): void
    {
        $this->seedHoldingScope();
        $this->seedDossier(101, 2, 201, 301);
        DB::table('organizations')->where('id', 1)->update(['is_holding' => false]);

        self::assertSame(404, $this->controller([1, 2])->show($this->request(), 101)->getStatusCode());
    }

    private function controller(array $scope): HoldingLegalArchiveController
    {
        $organizationScope = Mockery::mock(ContextAwareOrganizationScope::class);
        $organizationScope->shouldReceive('getOrganizationScope')->with(1)->andReturn($scope);

        $access = Mockery::mock(LegalDocumentAuthorizer::class);
        $access->shouldReceive('authorize')->andReturnUsing(function (User $actor, object $document, string $ability): void {
            if ($ability === 'download' && ! $this->downloadsAllowed) {
                throw new AuthorizationException;
            }
        });

        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(
            fn (User $actor, string $permission): bool => $permission !== 'multi-organization.reports.financial'
                || $this->financialAllowed,
        );

        return new HoldingLegalArchiveController(
            $organizationScope,
            $access,
            Mockery::mock(LegalDocumentDownloadService::class),
            $authorization,
        );
    }

    private function request(): Request
    {
        $request = Request::create('/api/v1/landing/multi-organization/legal-archive/contracts/101', 'GET');
        $request->attributes->set('current_organization_id', 1);
        $request->setUserResolver(static function (): User {
            $actor = new User;
            $actor->id = 9;
            $actor->current_organization_id = 1;

            return $actor;
        });

        return $request;
    }

    private function seedHoldingScope(): void
    {
        DB::table('organizations')->insert([
            ['id' => 1, 'name' => 'Холдинг', 'is_holding' => true, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Дочерняя организация', 'parent_organization_id' => 1, 'is_holding' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Соседняя организация', 'parent_organization_id' => 9, 'is_holding' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'name' => 'Несвязанная организация', 'is_holding' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedDossier(int $contractId, int $organizationId, int $documentId, int $versionId): void
    {
        $this->seedContract($contractId, $organizationId);
        DB::table('legal_archive_documents')->insert([
            'id' => $documentId,
            'organization_id' => $organizationId,
            'title' => 'Договор поставки',
            'document_type' => 'contract.supply',
            'status' => 'active',
            'signature_status' => 'not_required',
            'legal_significance_status' => 'original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_archive_document_links')->insert([
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'link_type' => 'source',
            'linked_type' => Contract::class,
            'linked_id' => $contractId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_archive_document_files')->insert([
            'id' => $documentId,
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'title' => 'Основной файл',
            'role' => 'primary',
            'current_version_id' => $versionId,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_archive_document_versions')->insert([
            'id' => $versionId,
            'organization_id' => $organizationId,
            'document_id' => $documentId,
            'document_file_id' => $documentId,
            'version_number' => 1,
            'processing_status' => 'ready',
            'original_filename' => 'contract.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedContract(int $contractId, int $organizationId): void
    {
        DB::table('contracts')->insert([
            'id' => $contractId,
            'organization_id' => $organizationId,
            'number' => 'Д-'.$contractId,
            'status' => 'active',
            'total_amount' => 1000.0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('parent_organization_id')->nullable();
            $table->boolean('is_holding')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('number')->nullable();
            $table->string('status')->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('title');
            $table->string('document_type')->nullable();
            $table->string('status')->nullable();
            $table->string('signature_status')->nullable();
            $table->string('legal_significance_status')->nullable();
            $table->date('document_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('legal_archive_document_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('link_type');
            $table->string('linked_type')->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();
            $table->timestamps();
        });
        Schema::create('legal_archive_document_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('title')->nullable();
            $table->string('role')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id')->nullable();
            $table->unsignedInteger('version_number');
            $table->string('processing_status')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
        Schema::create('legal_document_signatures', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('payment_documents', static function (Blueprint $table): void {
            $table->id();
            $table->string('invoiceable_type');
            $table->unsignedBigInteger('invoiceable_id');
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->string('status');
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
