<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Workflow\LegalWorkflowHistoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

final class LegalWorkflowHistoryTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(\Tests\Support\IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $schema = $this->database->schema();
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        $schema->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->integer('version_number');
        });
        $schema->create('legal_workflow_steps', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('instance_id');
            $table->string('label');
        });
        $schema->create('legal_workflow_instances', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
        });
        $schema->create('legal_workflow_decisions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('instance_id');
            $table->unsignedBigInteger('step_id')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_type');
            $table->string('action');
            $table->text('comment')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at');
        });
        $this->database->table('users')->insert(['id' => 8, 'name' => 'Анна Петрова']);
        $this->database->table('legal_workflow_instances')->insert(['id' => 1, 'organization_id' => 38, 'document_id' => 10]);
        $this->database->table('legal_archive_document_versions')->insert([
            ['id' => 100, 'organization_id' => 38, 'document_id' => 10, 'version_number' => 1],
            ['id' => 101, 'organization_id' => 38, 'document_id' => 10, 'version_number' => 2],
        ]);
        $this->database->table('legal_workflow_steps')->insert(['id' => 1, 'organization_id' => 38, 'instance_id' => 1, 'label' => 'Проверка условий']);
    }

    protected function tearDown(): void
    {
        foreach (['legal_workflow_decisions', 'legal_workflow_steps', 'legal_workflow_instances', 'legal_archive_document_versions', 'users'] as $table) {
            $this->database->schema()->dropIfExists($table);
        }
        $this->database->getConnection()->disconnect();
        parent::tearDown();
    }

    public function test_history_keeps_return_comments_across_versions_and_pages_without_foreign_documents(): void
    {
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 10, 'organization_id' => 38]);
        $actor = new User;
        $access = $this->createMock(LegalDocumentAuthorizer::class);
        $access->expects(self::exactly(3))->method('authorizePermission')
            ->with($actor, $document, 'legal_archive.workflow.view');
        for ($id = 1; $id <= 21; $id++) {
            $this->decision($id, ['document_version_id' => $id === 1 ? 100 : 101]);
        }
        $this->decision(22, ['document_id' => 11]);
        $this->decision(23, ['organization_id' => 39]);
        $service = new LegalWorkflowHistoryService($access);

        $page = $service->forDocument($actor, $document);
        self::assertCount(20, $page['items']);
        self::assertSame(2, $page['next_before_id']);
        self::assertSame(21, $page['items']->first()->id);
        self::assertSame('Анна Петрова', $page['items']->first()->actor_name);
        self::assertSame('Проверка условий', $page['items']->first()->step_label);
        self::assertSame(2, $page['items']->first()->version_number);
        $this->decision(24);

        $older = $service->forDocument($actor, $document, $page['next_before_id']);
        self::assertCount(1, $older['items']);
        self::assertNull($older['next_before_id']);
        self::assertSame(1, $older['items']->first()->version_number);
        self::assertSame("Уточнить срок.\nУказать дату завершения.", $older['items']->first()->comment);
        self::assertSame('Нужна новая редакция', $older['items']->first()->reason);
        self::assertSame('return', $older['items']->first()->action);
        self::assertCount(0, $service->forDocument($actor, $document, 1)['items']);
    }

    public function test_denied_document_does_not_return_history(): void
    {
        $access = $this->createMock(LegalDocumentAuthorizer::class);
        $access->method('authorizePermission')->willThrowException(new AuthorizationException);
        $service = new LegalWorkflowHistoryService($access);
        $this->expectException(AuthorizationException::class);
        $service->forDocument(new User, new LegalArchiveDocument);
    }

    public function test_missing_actor_and_foreign_version_or_step_do_not_leak_related_details(): void
    {
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 10, 'organization_id' => 38]);
        $this->database->table('legal_archive_document_versions')->where('id', 100)->update(['document_id' => 11]);
        $this->database->table('legal_workflow_steps')->where('id', 1)->update(['instance_id' => 99]);
        $this->decision(1, ['actor_user_id' => null, 'actor_type' => 'system']);

        $service = new LegalWorkflowHistoryService($this->createMock(LegalDocumentAuthorizer::class));
        $item = $service->forDocument(new User, $document)['items']->first();

        self::assertNull($item->actor_name);
        self::assertNull($item->version_number);
        self::assertNull($item->step_label);
        self::assertSame('system', $item->actor_type);
    }

    private function decision(int $id, array $overrides = []): void
    {
        $this->database->table('legal_workflow_decisions')->insert(array_replace([
            'id' => $id, 'organization_id' => 38, 'document_id' => 10,
            'document_version_id' => 100, 'instance_id' => 1, 'step_id' => 1,
            'actor_user_id' => 8, 'actor_type' => 'user', 'action' => 'return',
            'comment' => "Уточнить срок.\nУказать дату завершения.",
            'reason' => 'Нужна новая редакция', 'decided_at' => '2026-09-02 10:00:00+00',
        ], $overrides));
    }
}
