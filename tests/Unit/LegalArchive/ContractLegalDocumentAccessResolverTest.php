<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\ContractLegalDocumentAccessResolver;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class ContractLegalDocumentAccessResolverTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        Model::clearBootedModels();

        $this->database->schema()->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->string('number');
            $table->unsignedBigInteger('legal_archive_document_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->database->schema()->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('title');
            $table->string('document_type');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->database->schema()->create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('status')->nullable();
            $table->softDeletes();
        });

        $this->database->schema()->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
        });

        $this->database->schema()->create('legal_archive_document_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('link_type');
            $table->string('linked_type')->nullable();
            $table->string('linked_id')->nullable();
            $table->string('display_name');
            $table->timestamps();
        });

        $this->database->schema()->create('legal_archive_document_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('role');
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->database->schema()->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id');
            $table->string('version_number');
            $table->string('status');
            $table->string('processing_status');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->database->schema()->create('legal_workflow_instances', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('status');
            $table->timestamps();
        });

        $this->database->schema()->create('legal_workflow_steps', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workflow_instance_id');
            $table->string('status');
            $table->timestamps();
        });

        $this->database->schema()->create('legal_document_obligations', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamps();
        });

        $this->database->schema()->create('legal_signature_requests', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->timestamps();
        });

        $this->database->schema()->create('legal_document_signatures', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->timestamps();
        });
    }

    public function test_resolves_contract_document_by_contract_owner_boundary_not_current_actor_organization(): void
    {
        $this->database->table('legal_archive_documents')->insert([
            'id' => 42,
            'organization_id' => 7,
            'primary_project_id' => 11,
            'title' => 'Договор подряда',
            'document_type' => 'contract',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->database->table('contracts')->insert([
            'id' => 9,
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-9',
            'legal_archive_document_id' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = (new ContractLegalDocumentAccessResolver)->resolveDocument(11, 9, 42);

        self::assertNotNull($context);
        self::assertSame(7, (int) $context->contract->organization_id);
        self::assertSame(42, (int) $context->document->id);
    }

    public function test_rejects_document_not_bound_to_requested_contract_context(): void
    {
        $this->database->table('legal_archive_documents')->insert([
            [
                'id' => 42,
                'organization_id' => 7,
                'primary_project_id' => 11,
                'title' => 'Договор подряда',
                'document_type' => 'contract',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 43,
                'organization_id' => 7,
                'primary_project_id' => 11,
                'title' => 'Чужой документ',
                'document_type' => 'contract',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->database->table('contracts')->insert([
            'id' => 9,
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-9',
            'legal_archive_document_id' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = (new ContractLegalDocumentAccessResolver)->resolveDocument(11, 9, 43);

        self::assertNull($context);
    }

    public function test_resolves_version_only_inside_bound_contract_document(): void
    {
        $this->database->table('legal_archive_documents')->insert([
            'id' => 42,
            'organization_id' => 7,
            'primary_project_id' => 11,
            'title' => 'Договор подряда',
            'document_type' => 'contract',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->database->table('contracts')->insert([
            'id' => 9,
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-9',
            'legal_archive_document_id' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->database->table('legal_archive_document_files')->insert([
            'id' => 5,
            'organization_id' => 7,
            'document_id' => 42,
            'role' => 'primary',
            'title' => 'Файл договора',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->database->table('legal_archive_document_versions')->insert([
            'id' => 77,
            'organization_id' => 7,
            'document_id' => 42,
            'document_file_id' => 5,
            'version_number' => '1',
            'status' => 'uploaded',
            'processing_status' => 'ready',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $context = (new ContractLegalDocumentAccessResolver)->resolveVersion(11, 9, 42, 77);

        self::assertNotNull($context);
        self::assertSame(77, (int) $context->version->id);
    }
}
