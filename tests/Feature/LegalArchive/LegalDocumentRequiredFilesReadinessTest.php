<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Files\LegalDocumentFileRequirements;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use App\Services\LegalArchive\Workflow\LegalDocumentWorkflowReadinessGuard;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegalDocumentRequiredFilesReadinessTest extends TestCase
{
    private Capsule $database;

    private Container $previousContainer;

    private ?Container $previousFacade;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        $translator = new Translator(new ArrayLoader, 'ru');
        $container->instance('validator', new Factory($translator, $container));
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $this->database = new Capsule($container);
        $this->database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $schema = $this->database->schema();
        $schema->create('legal_archive_document_type_profiles', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->boolean('is_active');
        });
        $schema->create('legal_archive_document_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('role');
            $table->string('title')->default('Приложение');
            $table->unsignedBigInteger('current_version_id')->nullable();
        });
        $schema->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_file_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('processing_status');
            $table->string('status');
            $table->string('content_hash');
            $table->boolean('is_current');
        });
    }

    protected function tearDown(): void
    {
        $this->database->schema()->dropIfExists('legal_archive_document_versions');
        $this->database->schema()->dropIfExists('legal_archive_document_files');
        $this->database->schema()->dropIfExists('legal_archive_document_type_profiles');
        $this->database->getConnection()->disconnect();
        Model::clearBootedModels();
        Container::setInstance($this->previousContainer);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacade);
        parent::tearDown();
    }

    #[DataProvider('unreadyAttachments')]
    public function test_required_attachment_must_have_a_ready_current_version(?string $processingStatus): void
    {
        $document = $this->document();
        if ($processingStatus !== null) {
            $this->attachment($processingStatus);
        }

        $this->expectException(ValidationException::class);
        $this->guard()->assertReady($document);
    }

    public static function unreadyAttachments(): array
    {
        return [
            'missing file' => [null],
            'security check pending' => ['quarantine'],
            'security check failed' => ['failed'],
        ];
    }

    public function test_ready_required_attachment_allows_readiness_validation(): void
    {
        $this->attachment('ready');
        $this->guard()->assertReady($this->document());
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidVersionLinks')]
    public function test_unrelated_or_invalid_current_version_does_not_satisfy_requirement(array $changes): void
    {
        $this->attachment('ready');
        $this->database->table('legal_archive_document_versions')->where('id', 41)->update($changes);

        $this->expectException(ValidationException::class);
        $this->guard()->assertReady($this->document());
    }

    public static function invalidVersionLinks(): array
    {
        return [
            'another organization' => [['organization_id' => 8]],
            'another document' => [['document_id' => 18]],
            'another file' => [['document_file_id' => 32]],
            'not current' => [['is_current' => false]],
            'missing hash' => [['content_hash' => '']],
            'invalid hash' => [['content_hash' => str_repeat('g', 64)]],
        ];
    }

    public function test_file_and_version_from_another_organization_cannot_satisfy_requirement(): void
    {
        $this->attachment('ready');
        $this->database->table('legal_archive_document_files')->where('id', 31)->update(['organization_id' => 8]);
        $this->database->table('legal_archive_document_versions')->where('id', 41)->update(['organization_id' => 8]);

        $this->expectException(ValidationException::class);
        $this->guard()->assertReady($this->document());
    }

    public function test_bulk_blockers_use_one_file_query_and_keep_document_boundaries(): void
    {
        $this->attachment('ready');
        $documents = collect([$this->document(), $this->document()->forceFill(['id' => 18])]);
        $connection = $this->database->getConnection();
        $connection->enableQueryLog();

        $blockers = $this->guard()->blockersFor($documents);

        $this->assertSame([18 => 'legal_archive.workflow.blockers.required_files_missing'], $blockers);
        $this->assertCount(2, $connection->getQueryLog());
        $connection->disableQueryLog();
    }

    public function test_primary_only_profile_does_not_query_additional_files(): void
    {
        $connection = $this->database->getConnection();
        $connection->enableQueryLog();

        $this->guard(['primary'])->assertReady($this->document());

        $this->assertSame([], $connection->getQueryLog());
        $connection->disableQueryLog();
    }

    private function document(): LegalArchiveDocument
    {
        return (new LegalArchiveDocument)->forceFill([
            'id' => 17,
            'organization_id' => 7,
            'document_type' => 'contract',
            'type_profile_code' => 'contract.test',
            'structured_fields' => [],
        ]);
    }

    private function attachment(string $processingStatus): void
    {
        $this->database->table('legal_archive_document_files')->insert([
            'id' => 31, 'document_id' => 17, 'organization_id' => 7,
            'role' => 'appendix', 'current_version_id' => 41,
        ]);
        $this->database->table('legal_archive_document_versions')->insert([
            'id' => 41, 'document_id' => 17, 'document_file_id' => 31, 'organization_id' => 7,
            'processing_status' => $processingStatus, 'status' => 'uploaded',
            'content_hash' => str_repeat('a', 64), 'is_current' => true,
        ]);
    }

    private function guard(array $roles = ['appendix']): LegalDocumentWorkflowReadinessGuard
    {
        return new LegalDocumentWorkflowReadinessGuard(
            new LegalDocumentProfileRegistry(
                static fn (): ?array => null,
                ['contract.test' => [
                    'label' => 'Учебный договор', 'category' => 'contract',
                    'required_file_roles' => $roles, 'required_fields' => [], 'schema' => [],
                ]],
            ),
            new LegalDocumentProfileValidator,
        );
    }

    public function test_requirements_report_each_role_without_treating_another_file_as_ready(): void
    {
        $this->attachment('ready');
        $registry = new LegalDocumentProfileRegistry(static fn (): ?array => null, [
            'contract.test' => ['label' => 'Учебный договор', 'category' => 'contract',
                'required_file_roles' => ['primary', 'appendix', 'specification'], 'required_fields' => [], 'schema' => []],
        ]);
        $requirements = (new LegalDocumentFileRequirements)->forDocuments(collect([$this->document()]), [
            7 => ['contract.test' => $registry->find(7, 'contract.test')],
        ]);

        $this->assertSame(['appendix', 'specification'], array_column($requirements[17], 'role'));
        $this->assertSame([true, false], array_column($requirements[17], 'ready'));
    }

    public function test_file_snapshot_contains_exact_current_version_and_excludes_other_tenants(): void
    {
        $this->attachment('ready');
        $snapshot = (new LegalDocumentFileRequirements)->snapshotFor($this->document());

        $this->assertSame([[
            'file_id' => 31, 'role' => 'appendix', 'title' => 'Приложение',
            'version_id' => 41, 'content_hash' => str_repeat('a', 64),
        ]], $snapshot);

        $this->database->table('legal_archive_document_versions')->where('id', 41)->update(['organization_id' => 8]);
        $this->assertSame([], (new LegalDocumentFileRequirements)->snapshotFor($this->document()));
    }
}
