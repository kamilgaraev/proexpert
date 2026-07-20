<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Comments\LegalDocumentCommentService;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class LegalDocumentCommentWorkflowTest extends TestCase
{
    private Capsule $database;

    private LegalDocumentCommentService $service;

    private RecordingCommentAudit $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->schema();
        $access = $this->createMock(LegalDocumentAuthorizer::class);
        $access->method('authorize');
        $this->audit = new RecordingCommentAudit;
        $this->service = new LegalDocumentCommentService($access, $this->audit, $this->database->getConnection());
    }

    public function test_comment_is_bound_to_exact_tenant_document_version_and_typed_anchor(): void
    {
        [$document, $version, $actor] = $this->fixture();

        $comment = $this->service->create(
            $document,
            $actor,
            (int) $version->id,
            'Исправьте реквизиты',
            3,
            ['type' => 'rect', 'x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1],
            'all_parties',
            true,
            'comment-1',
        );

        self::assertSame('open', $comment->status);
        self::assertSame(3, $comment->page_number);
        self::assertSame('rect', $comment->anchor['type']);
        self::assertTrue($comment->is_blocking);
        self::assertSame(['comment_created'], $this->audit->events);
    }

    public function test_create_is_idempotent_and_rejects_payload_drift(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $first = $this->service->create($document, $actor, (int) $version->id, 'Замечание', 1, null, 'internal', false, 'same-key');
        $replay = $this->service->create($document, $actor, (int) $version->id, 'Замечание', 1, null, 'internal', false, 'same-key');
        self::assertSame($first->id, $replay->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_comment_idempotency_conflict');
        $this->service->create($document, $actor, (int) $version->id, 'Другой текст', 1, null, 'internal', false, 'same-key');
    }

    public function test_resolution_is_idempotent_and_preserves_resolution_evidence(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $comment = $this->service->create($document, $actor, (int) $version->id, 'Блокер', null, null, 'internal', true, 'create');

        $resolved = $this->service->resolve($document, $comment, $actor, 'Исправлено', 'resolve-1');
        $replay = $this->service->resolve($document, $resolved->refresh(), $actor, 'Исправлено', 'resolve-1');

        self::assertSame($resolved->id, $replay->id);
        self::assertSame('resolved', $replay->status);
        self::assertSame('Исправлено', $replay->resolution);
        self::assertSame((int) $actor->id, (int) $replay->resolved_by_user_id);
        self::assertNotNull($replay->resolved_at);
        self::assertSame(['comment_created', 'comment_resolved'], $this->audit->events);
    }

    public function test_foreign_version_and_invalid_visibility_fail_closed(): void
    {
        [$document, , $actor] = $this->fixture();
        $foreign = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => 20,
            'document_id' => 999,
            'content_hash' => str_repeat('b', 64),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_comment_version_not_found');
        $this->service->create($document, $actor, (int) $foreign->id, 'Текст', null, null, 'internal', false, 'foreign');
    }

    private function fixture(): array
    {
        $document = LegalArchiveDocument::query()->create(['organization_id' => 10, 'title' => 'Досье']);
        $version = LegalArchiveDocumentVersion::query()->create([
            'organization_id' => 10,
            'document_id' => $document->id,
            'content_hash' => str_repeat('a', 64),
        ]);
        $actor = new User;
        $actor->forceFill(['id' => 7, 'current_organization_id' => 10]);
        $actor->exists = true;

        return [$document, $version, $actor];
    }

    private function schema(): void
    {
        $schema = $this->database->schema();
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('content_hash', 64);
            $table->timestamps();
        });
        $schema->create('legal_document_comments', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('author_user_id');
            $table->text('body');
            $table->unsignedInteger('page_number')->nullable();
            $table->json('anchor')->nullable();
            $table->string('visibility');
            $table->boolean('is_blocking');
            $table->string('status');
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->string('resolution_idempotency_key')->nullable();
            $table->string('resolution_request_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_id', 'author_user_id', 'idempotency_key']);
        });
    }
}

final class RecordingCommentAudit implements LegalDocumentAudit
{
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, \App\Models\Contract $contract, ?int $actorId, array $context = []): void {}
}
