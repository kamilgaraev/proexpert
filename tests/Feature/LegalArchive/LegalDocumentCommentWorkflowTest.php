<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Audit\LegalDocumentSourceEventId;
use App\Services\LegalArchive\Comments\LegalDocumentCommentService;
use App\Services\LegalArchive\Workflow\LegalWorkflowAssignmentValidator;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
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

    private array $activeResponsibleUserIds = [8];

    private array $approveActorUserIds = [9];

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
        $access->method('authorize')->willReturnCallback(
            function (User $actor, LegalArchiveDocument $document, string $ability): void {
                if ($ability === 'approve' && ! in_array((int) $actor->id, $this->approveActorUserIds, true)) {
                    throw new AuthorizationException;
                }
            },
        );
        $access->method('authorizePermission')->willReturnCallback(
            static function (User $actor): void {
                if ((int) $actor->id !== 9) {
                    throw new AuthorizationException;
                }
            },
        );
        $this->audit = new RecordingCommentAudit;
        $assignmentValidator = new LegalWorkflowAssignmentValidator(
            fn (string $actorType, string $reference): bool => $actorType === 'user'
                && in_array((int) $reference, $this->activeResponsibleUserIds, true),
        );
        $this->service = new LegalDocumentCommentService(
            $access,
            $this->audit,
            $this->database->getConnection(),
            assignmentValidator: $assignmentValidator,
        );
    }

    public function test_comment_is_bound_to_exact_tenant_document_version_and_typed_anchor(): void
    {
        [$document, $version, $actor] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();

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
        $comment = $this->service->create($document, $actor, (int) $version->id, 'Блокер', null, null, 'internal', false, 'create');

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

    public function test_audit_idempotency_is_namespaced_by_actor_and_comment(): void
    {
        [$document, $version, $firstActor] = $this->fixture();
        $secondActor = $this->actor(8, 10);
        $first = $this->service->create($document, $firstActor, (int) $version->id, 'Первое', idempotencyKey: 'same');
        $second = $this->service->create($document, $secondActor, (int) $version->id, 'Второе', idempotencyKey: 'same');
        $this->service->resolve($document, $first, $firstActor, idempotencyKey: 'resolve');
        $this->service->resolve($document, $second, $secondActor, idempotencyKey: 'resolve');

        self::assertSame([
            LegalDocumentSourceEventId::canonical('comment:create:actor:7', 'same'),
            LegalDocumentSourceEventId::canonical('comment:create:actor:8', 'same'),
            LegalDocumentSourceEventId::canonical("comment:resolve:comment:{$first->id}", 'resolve'),
            LegalDocumentSourceEventId::canonical("comment:resolve:comment:{$second->id}", 'resolve'),
        ], array_column($this->audit->contexts, 'source_event_id'));
        self::assertSame(['same', 'same', 'resolve', 'resolve'], array_column($this->audit->contexts, 'idempotency_key'));
    }

    public function test_visibility_scope_prevents_internal_and_responsible_comment_leaks(): void
    {
        [$document, $version, $author] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();
        $internal = $this->service->create($document, $author, (int) $version->id, 'Внутренний', visibility: 'internal');
        $shared = $this->service->create($document, $author, (int) $version->id, 'Общий', visibility: 'all_parties');
        $responsible = $this->service->create($document, $author, (int) $version->id, 'Ответственному', visibility: 'author_and_responsible');

        self::assertEqualsCanonicalizing(
            [(int) $internal->id, (int) $shared->id],
            $this->service->visible($document, $this->actor(9, 10))->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
        self::assertEqualsCanonicalizing(
            [(int) $shared->id],
            $this->service->visible($document, $this->actor(20, 30))->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
        self::assertEqualsCanonicalizing(
            [(int) $internal->id, (int) $shared->id, (int) $responsible->id],
            $this->service->visible($document, $this->actor(8, 10))->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
    }

    public function test_author_can_resolve_own_blocking_comment(): void
    {
        [$document, $version, $author] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();
        $blocking = $this->service->create($document, $author, (int) $version->id, 'Блокер', blocking: true);

        self::assertSame('resolved', $this->service->resolve($document, $blocking, $author)->status);
    }

    public function test_responsible_and_authorized_manager_can_resolve_blocking_comments(): void
    {
        [$document, $version, $author] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();
        $responsibleBlocker = $this->service->create($document, $author, (int) $version->id, 'Ответственный', blocking: true);
        $managerBlocker = $this->service->create(
            $document,
            $author,
            (int) $version->id,
            'Менеджер',
            visibility: 'author_and_responsible',
            blocking: true,
        );

        self::assertSame('resolved', $this->service->resolve($document, $responsibleBlocker, $this->actor(8, 10))->status);
        self::assertContains(
            (int) $managerBlocker->id,
            $this->service->visible($document, $this->actor(9, 10))->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
        self::assertSame('resolved', $this->service->resolve($document, $managerBlocker, $this->actor(9, 10))->status);
    }

    public function test_restricted_blocking_comment_manager_visibility_and_resolution_require_exact_approve_ability(): void
    {
        foreach (['restricted', 'secret'] as $confidentialityLevel) {
            [$document, $version, $author] = $this->fixture();
            $document->forceFill([
                'responsible_user_id' => 8,
                'confidentiality_level' => $confidentialityLevel,
            ])->save();
            $blocking = $this->service->create(
                $document,
                $author,
                (int) $version->id,
                'Закрытое замечание',
                visibility: 'author_and_responsible',
                blocking: true,
            );

            $this->approveActorUserIds = [11];
            foreach ([9, 6] as $actorId) {
                $actor = $this->actor($actorId, 10);

                self::assertNotContains(
                    (int) $blocking->id,
                    $this->service->visible($document, $actor)->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
                );
                try {
                    $this->service->resolve($document, $blocking, $actor);
                    self::fail('Manager without exact approve ability resolved a restricted blocking comment.');
                } catch (DomainException $exception) {
                    self::assertSame('legal_document_comment_not_found', $exception->getMessage());
                }
            }

            $approveActor = $this->actor(11, 10);
            self::assertContains(
                (int) $blocking->id,
                $this->service->visible($document, $approveActor)->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            );
            self::assertSame('resolved', $this->service->resolve($document, $blocking, $approveActor)->status);
        }
    }

    public function test_unrelated_internal_user_cannot_resolve_blocking_comment(): void
    {
        [$document, $version, $author] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();
        $blocking = $this->service->create($document, $author, (int) $version->id, 'Блокер', blocking: true);

        $this->expectException(AuthorizationException::class);
        $this->service->resolve($document, $blocking, $this->actor(6, 10));
    }

    public function test_blocking_comment_requires_active_responsible_in_document_scope(): void
    {
        [$document, $version, $author] = $this->fixture();

        try {
            $this->service->create($document, $author, (int) $version->id, 'Нет ответственного', blocking: true);
            self::fail('Blocking comment without responsible user was accepted.');
        } catch (DomainException $exception) {
            self::assertSame('legal_document_comment_responsible_required', $exception->getMessage());
        }

        $document->forceFill(['responsible_user_id' => 8])->save();
        $this->activeResponsibleUserIds = [];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_comment_responsible_inactive');
        $this->service->create($document, $author, (int) $version->id, 'Неактивный ответственный', blocking: true);
    }

    public function test_author_or_manager_can_resolve_after_responsible_is_deactivated(): void
    {
        [$document, $version, $author] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();
        $authorBlocker = $this->service->create($document, $author, (int) $version->id, 'Автор исправляет', blocking: true);
        $managerBlocker = $this->service->create($document, $author, (int) $version->id, 'Менеджер исправляет', blocking: true);
        $this->activeResponsibleUserIds = [];

        self::assertSame('resolved', $this->service->resolve($document, $authorBlocker, $author)->status);
        self::assertSame('resolved', $this->service->resolve($document, $managerBlocker, $this->actor(9, 10))->status);
    }

    public function test_external_actor_cannot_create_blocker_but_can_use_non_blocking_comment_flow(): void
    {
        [$document, $version] = $this->fixture();
        $document->forceFill(['responsible_user_id' => 8])->save();
        $external = $this->actor(20, 30);

        try {
            $this->service->create(
                $document,
                $external,
                (int) $version->id,
                'Внешний блокер',
                visibility: 'all_parties',
                blocking: true,
            );
            self::fail('External actor created a blocking comment.');
        } catch (AuthorizationException) {
        }

        $comment = $this->service->create(
            $document,
            $external,
            (int) $version->id,
            'Обычное замечание',
            visibility: 'all_parties',
        );

        self::assertSame('resolved', $this->service->resolve($document, $comment, $external)->status);
    }

    public function test_anchor_rejects_numeric_strings_and_non_finite_values(): void
    {
        [$document, $version, $actor] = $this->fixture();

        foreach (['0.1', NAN, INF] as $invalid) {
            try {
                $this->service->create(
                    $document,
                    $actor,
                    (int) $version->id,
                    'Якорь',
                    anchor: ['type' => 'rect', 'x' => $invalid, 'y' => 0.2, 'width' => 0.3, 'height' => 0.1],
                );
                self::fail('Invalid anchor coordinate was accepted.');
            } catch (DomainException $exception) {
                self::assertSame('legal_document_comment_anchor_invalid', $exception->getMessage());
            }
        }
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

    private function actor(int $id, int $organizationId): User
    {
        $actor = new User;
        $actor->forceFill(['id' => $id, 'current_organization_id' => $organizationId]);
        $actor->exists = true;

        return $actor;
    }

    private function schema(): void
    {
        $schema = $this->database->schema();
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('title');
            $table->string('confidentiality_level')->default('internal');
            $table->unsignedBigInteger('responsible_user_id')->nullable();
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

    public array $contexts = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        $this->events[] = $event;
        $this->contexts[] = $context;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, \App\Models\Contract $contract, ?int $actorId, array $context = []): void {}
}
