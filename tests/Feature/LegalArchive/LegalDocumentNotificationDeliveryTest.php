<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentNotificationDelivery;
use App\BusinessModules\Features\LegalArchive\Models\LegalWorkflowStep;
use App\BusinessModules\Features\Notifications\Enums\NotificationInterface;
use App\BusinessModules\Features\Notifications\Models\Notification;
use App\BusinessModules\Features\Notifications\Services\DatabaseNotificationCommitSequencer;
use App\BusinessModules\Features\Notifications\Services\DatabaseNotificationPersistence;
use App\BusinessModules\Features\Notifications\Services\NotificationInterfaceCursorStore;
use App\BusinessModules\Features\Notifications\Services\NotificationPayloadNormalizer;
use App\BusinessModules\Features\Notifications\Services\NotificationRecipientPermissionResolver;
use App\BusinessModules\Features\Notifications\Services\NotificationService;
use App\BusinessModules\Features\Notifications\Services\NotificationTargetResolver;
use App\BusinessModules\Features\Notifications\Services\PreferenceManager;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\PermissionResolver;
use App\Domain\Authorization\Services\RoleScanner;
use App\Enums\UserProjectAccessMode;
use App\Models\User;
use App\Notifications\LegalArchive\LegalDocumentApprovalRequiredNotification;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\LegalDocumentNotificationPublisher;
use App\Services\LegalArchive\LegalDocumentNotificationRecoveryService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActorResolver;
use App\Services\LegalArchive\Workflow\LegalWorkflowApprovalNotifier;
use App\Services\Logging\LoggingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use Tests\Support\IsolatedPostgresTestDatabase;

final class LegalDocumentNotificationDeliveryTest extends TestCase
{
    private Capsule $database;

    private mixed $previousFacadeApplication;

    private Container $previousContainer;

    private CapturingLegalNotificationTransport $notificationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'notifications' => ['types' => ['legal_archive' => ['mandatory' => true]]],
        ]));
        $this->database = new Capsule($container);
        $this->database->addConnection(IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        $container->instance('db', $this->database->getDatabaseManager());
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Model::clearBootedModels();
        $this->createSchema();
        $container->instance(LegalDocumentAuthorizer::class, $this->createStub(LegalDocumentAuthorizer::class));
        $this->notificationService = new CapturingLegalNotificationTransport(
            new PreferenceManager,
            new NotificationPayloadNormalizer,
            new NotificationRecipientPermissionResolver($this->createStub(AuthorizationService::class)),
            new NotificationTargetResolver,
            new DatabaseNotificationPersistence(new NotificationInterfaceCursorStore),
            new DatabaseNotificationCommitSequencer,
        );
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Model::clearBootedModels();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_published_approval_notification_is_visible_in_admin_and_replay_does_not_duplicate_it(): void
    {
        [$document, $recipient] = $this->dossier();
        $publisher = new LegalDocumentNotificationPublisher($this->notificationService);
        $message = new LegalDocumentApprovalRequiredNotification($document);

        $publisher->publish($document, $recipient, 'workflow-step:19:8', $message);
        $publisher->publish($document, $recipient, 'workflow-step:19:8', $message);

        self::assertSame(1, Notification::query()->forUser($recipient)->count());
        self::assertSame(1, $this->database->table('legal_document_notification_deliveries')->where('status', 'delivered')->count());
        self::assertSame(
            1,
            Notification::query()->forUser($recipient)->forOrganization(7)->forInterface(NotificationInterface::Admin)->count(),
            'Доставленное уведомление должно попадать в выдачу административного интерфейса.',
        );
        self::assertSame(0, Notification::query()->forUser($recipient)->forInterface(NotificationInterface::Customer)->count());
        $notification = Notification::query()->sole();
        self::assertSame($notification->id, $this->database->table('legal_document_notification_deliveries')->value('notification_id'));
        self::assertSame(['in_app', 'websocket'], $notification->channels);
        self::assertSame([$notification->id], $this->notificationService->queuedIds);
        self::assertGreaterThan(0, (new NotificationInterfaceCursorStore)->latest($recipient, NotificationInterface::Admin));
    }

    public function test_target_failure_rolls_back_notification_and_retry_keeps_reserved_identifier(): void
    {
        [$document, $recipient] = $this->dossier();
        $publisher = new LegalDocumentNotificationPublisher($this->notificationService);
        $message = new LegalDocumentApprovalRequiredNotification($document);
        $this->database->getConnection()->statement("ALTER TABLE notification_targets ADD CONSTRAINT test_reject_admin CHECK (interface <> 'admin')");

        try {
            $publisher->publish($document, $recipient, 'workflow-step:19:8', $message);
            self::fail('Ошибка сохранения адресата уведомления должна прервать доставку.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('test_reject_admin', $exception->getMessage());
        }

        self::assertSame(0, Notification::query()->count());
        self::assertSame(0, $this->database->table('notification_targets')->count());
        self::assertSame(0, $this->database->table('notification_interface_cursors')->count());
        self::assertSame([], $this->notificationService->queuedIds);
        $delivery = LegalDocumentNotificationDelivery::query()->sole();
        self::assertSame('sending', $delivery->status);
        $reservedId = $delivery->notification_id;
        $this->database->getConnection()->statement('ALTER TABLE notification_targets DROP CONSTRAINT test_reject_admin');

        $delivery->forceFill(['lease_expires_at' => now()->subMinute()])->save();
        $recovery = new LegalDocumentNotificationRecoveryService($publisher);
        self::assertSame(1, $recovery->recoverExpired());
        self::assertSame(0, $recovery->recoverExpired());
        self::assertSame($reservedId, Notification::query()->sole()->id);
        self::assertSame('delivered', $delivery->fresh()->status);
        self::assertSame(1, Notification::query()->forUser($recipient)->forInterface(NotificationInterface::Admin)->count());
        self::assertSame([$reservedId], $this->notificationService->queuedIds);
    }

    public function test_recovery_completes_existing_targetless_notification_without_creating_a_second_row(): void
    {
        [$document, $recipient] = $this->dossier();
        $delivery = $this->expiredDelivery($document, $recipient);
        $notification = Notification::query()->forceCreate([
            'id' => $delivery->notification_id,
            'type' => LegalDocumentApprovalRequiredNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => 8,
            'organization_id' => 7,
            'notification_type' => 'legal_archive',
            'priority' => 'normal',
            'channels' => ['in_app'],
            'delivery_status' => [],
            'data' => $delivery->notification_payload,
            'metadata' => [],
        ]);

        $recovery = new LegalDocumentNotificationRecoveryService(new LegalDocumentNotificationPublisher($this->notificationService));

        self::assertSame(1, $recovery->recoverExpired());
        self::assertSame(0, $recovery->recoverExpired());
        self::assertSame(1, Notification::query()->count());
        self::assertSame($notification->id, Notification::query()->sole()->id);
        self::assertSame(1, Notification::query()->forUser($recipient)->forInterface(NotificationInterface::Admin)->count());
        self::assertSame([$notification->id], $this->notificationService->queuedIds);
        self::assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_recovery_does_not_mark_another_recipients_notification_as_delivered(): void
    {
        [$document, $recipient] = $this->dossier();
        $otherRecipient = new User;
        $otherRecipient->forceFill(['id' => 9, 'current_organization_id' => 7]);
        $delivery = $this->expiredDelivery($document, $recipient);
        $notification = $this->notificationService->sendWithId(
            $delivery->notification_id,
            $otherRecipient,
            LegalDocumentApprovalRequiredNotification::class,
            ['title' => 'Другой документ'],
            notificationType: 'legal_archive',
            channels: ['in_app', 'websocket'],
            organizationId: 7,
            interfaces: ['admin'],
        );
        $this->notificationService->queuedIds = [];
        $recovery = new LegalDocumentNotificationRecoveryService(new LegalDocumentNotificationPublisher($this->notificationService));

        self::assertSame(0, $recovery->recoverExpired());
        self::assertSame('sending', $delivery->fresh()->status);
        self::assertSame(9, (int) $notification->fresh()->notifiable_id);
        self::assertSame('Другой документ', $notification->fresh()->data['title']);
        self::assertSame(0, Notification::query()->forUser($recipient)->count());
        self::assertSame([], $this->notificationService->queuedIds);
    }

    public function test_recovery_discards_delivery_when_document_access_was_revoked(): void
    {
        [$document, $recipient] = $this->dossier();
        $delivery = $this->expiredDelivery($document, $recipient);
        $access = $this->createStub(LegalDocumentAuthorizer::class);
        $access->method('authorize')->willThrowException(new AuthorizationException);
        Container::getInstance()->instance(LegalDocumentAuthorizer::class, $access);
        $recovery = new LegalDocumentNotificationRecoveryService(new LegalDocumentNotificationPublisher($this->notificationService));

        self::assertSame(0, $recovery->recoverExpired());
        self::assertSame('discarded', $delivery->fresh()->status);
        self::assertNull($delivery->fresh()->lease_expires_at);
        self::assertSame(0, Notification::query()->count());
        self::assertSame([], $this->notificationService->queuedIds);
    }

    private function expiredDelivery(LegalArchiveDocument $document, User $recipient): LegalDocumentNotificationDelivery
    {
        return LegalDocumentNotificationDelivery::query()->create([
            'document_id' => $document->id,
            'recipient_user_id' => $recipient->id,
            'delivery_key' => 'workflow-step:19:8',
            'status' => 'sending',
            'notification_id' => (string) Str::uuid(),
            'notification_type' => LegalDocumentApprovalRequiredNotification::class,
            'notification_payload' => (new LegalDocumentApprovalRequiredNotification($document))->toArray($recipient),
            'lease_token' => 'expired-test-lease',
            'lease_expires_at' => now()->subMinute(),
            'attempt_count' => 1,
        ]);
    }

    public function test_recovering_an_older_interface_target_does_not_rewind_the_admin_cursor(): void
    {
        [, $recipient] = $this->dossier();
        $olderId = (string) Str::uuid();
        $this->notificationService->sendWithId(
            $olderId,
            $recipient,
            LegalDocumentApprovalRequiredNotification::class,
            ['title' => 'Первое уведомление'],
            notificationType: 'legal_archive',
            channels: ['in_app'],
            organizationId: 7,
            interfaces: ['admin'],
        );
        $this->notificationService->send(
            $recipient,
            LegalDocumentApprovalRequiredNotification::class,
            ['title' => 'Новое уведомление'],
            notificationType: 'legal_archive',
            channels: ['in_app'],
            organizationId: 7,
            interfaces: ['admin'],
        );
        $cursors = new NotificationInterfaceCursorStore;
        $newerCursor = $cursors->latest($recipient, NotificationInterface::Admin);

        $this->notificationService->sendWithId(
            $olderId,
            $recipient,
            LegalDocumentApprovalRequiredNotification::class,
            ['title' => 'Первое уведомление'],
            notificationType: 'legal_archive',
            channels: ['in_app'],
            organizationId: 7,
            interfaces: ['admin', 'customer'],
        );

        self::assertSame($newerCursor, $cursors->latest($recipient, NotificationInterface::Admin));
        self::assertGreaterThan($newerCursor, $cursors->latest($recipient, NotificationInterface::Customer));
        self::assertSame(2, Notification::query()->count());
        self::assertSame(3, $this->database->table('notification_targets')->count());
    }

    private function dossier(): array
    {
        $this->database->table('legal_archive_documents')->insert([
            'id' => 42,
            'organization_id' => 7,
            'title' => 'Учебный договор',
        ]);
        $this->database->table('users')->insert(['id' => 8, 'current_organization_id' => 7]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 7, 'user_id' => 8, 'is_active' => true, 'project_access_mode' => UserProjectAccessMode::ALL_PROJECTS->value,
        ]);
        $recipient = User::query()->findOrFail(8);
        $this->database->table('legal_workflow_instances')->insert([
            'id' => 7, 'document_id' => 42, 'organization_id' => 7, 'status' => 'in_progress',
        ]);
        $this->database->table('legal_workflow_steps')->insert([
            'id' => 19, 'instance_id' => 7, 'organization_id' => 7, 'status' => 'active',
            'actor_type' => 'user', 'actor_reference' => '8', 'assignment_revision' => 0,
        ]);

        return [LegalArchiveDocument::query()->findOrFail(42), $recipient];
    }

    public function test_approval_notification_opens_only_the_contract_linked_in_the_same_organization_and_project(): void
    {
        [$document, $recipient] = $this->dossier();
        $document->primary_project_id = 52;
        $this->database->schema()->create('contracts', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->bigInteger('organization_id');
            $table->bigInteger('project_id');
            $table->bigInteger('legal_archive_document_id');
            $table->softDeletes();
        });
        $this->database->table('contracts')->insert([
            ['id' => 269, 'organization_id' => 8, 'project_id' => 52, 'legal_archive_document_id' => 42],
            ['id' => 270, 'organization_id' => 7, 'project_id' => 53, 'legal_archive_document_id' => 42],
            ['id' => 271, 'organization_id' => 7, 'project_id' => 52, 'legal_archive_document_id' => 42],
        ]);

        $publisher = new LegalDocumentNotificationPublisher($this->notificationService);
        $publisher->publish($document, $recipient, 'contract-route', new LegalDocumentApprovalRequiredNotification($document));

        self::assertSame('/projects/52/contracts/271/documents/42', Notification::query()->sole()->data['targetRoute']);
        $this->database->table('contracts')->where('id', 271)->update(['deleted_at' => now()]);
        $standalone = (new LegalDocumentApprovalRequiredNotification($document))->toArray($recipient);
        self::assertSame('/legal-archive/42', $standalone['targetRoute']);
    }

    public function test_active_step_is_delivered_once_per_assignment_revision_and_pending_steps_are_silent(): void
    {
        [$document, $recipient] = $this->dossier();
        $access = $this->createStub(LegalDocumentAuthorizer::class);
        $actors = new LegalWorkflowActorResolver(documentAuthorizer: $access);
        $notifier = new LegalWorkflowApprovalNotifier($actors, new LegalDocumentNotificationPublisher($this->notificationService));
        $step = (new LegalWorkflowStep)->forceFill([
            'id' => 19, 'organization_id' => 7, 'status' => 'pending', 'actor_type' => 'user', 'actor_reference' => '8', 'assignment_revision' => 0,
        ]);

        $notifier->publishForStep($document, $step);
        self::assertSame(0, Notification::query()->count());
        $step->status = 'active';
        $notifier->publishForStep($document, $step);
        $notifier->publishForStep($document, $step);
        self::assertSame(1, Notification::query()->forUser($recipient)->forInterface(NotificationInterface::Admin)->count());
        self::assertSame('workflow-step:19:8', LegalDocumentNotificationDelivery::query()->sole()->delivery_key);

        $step->assignment_revision = 1;
        $this->database->table('legal_workflow_steps')->where('id', 19)->update(['assignment_revision' => 1]);
        $notifier->publishForStep($document, $step);
        $notifier->publishForStep($document, $step);
        self::assertSame(2, Notification::query()->forUser($recipient)->forInterface(NotificationInterface::Admin)->count());
        self::assertSame(['workflow-step:19:8', 'workflow-step:19:8:assignment:1'], LegalDocumentNotificationDelivery::query()->orderBy('id')->pluck('delivery_key')->all());
        self::assertCount(2, $this->notificationService->queuedIds);
    }

    public function test_stale_step_snapshot_cannot_deliver_after_reassignment(): void
    {
        [$document] = $this->dossier();
        $step = LegalWorkflowStep::query()->findOrFail(19);
        $this->database->table('legal_workflow_steps')->where('id', 19)->update([
            'actor_reference' => '9', 'assignment_revision' => 1,
        ]);
        $notifier = new LegalWorkflowApprovalNotifier(
            new LegalWorkflowActorResolver(documentAuthorizer: $this->createStub(LegalDocumentAuthorizer::class)),
            new LegalDocumentNotificationPublisher($this->notificationService),
        );

        $notifier->publishForStep($document, $step);

        self::assertSame(0, Notification::query()->count());
        self::assertSame('discarded', LegalDocumentNotificationDelivery::query()->sole()->status);
        self::assertSame([], $this->notificationService->queuedIds);
    }

    public function test_recovery_discards_old_assignment_after_step_reassignment(): void
    {
        [$document, $recipient] = $this->dossier();
        $delivery = $this->expiredDelivery($document, $recipient);
        $this->database->table('legal_workflow_steps')->where('id', 19)->update([
            'actor_reference' => '9', 'assignment_revision' => 1,
        ]);
        $recovery = new LegalDocumentNotificationRecoveryService(new LegalDocumentNotificationPublisher($this->notificationService));

        self::assertSame(0, $recovery->recoverExpired());
        self::assertSame('discarded', $delivery->fresh()->status);
        self::assertSame(0, Notification::query()->count());
        self::assertSame([], $this->notificationService->queuedIds);
    }

    public function test_role_assignment_reaches_the_admin_notification_feed_with_real_role_lookup(): void
    {
        [$document, $recipient] = $this->dossier();
        $schema = $this->database->schema();
        $schema->create('authorization_contexts', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->string('type');
            $table->bigInteger('resource_id')->nullable();
            $table->bigInteger('parent_context_id')->nullable();
        });
        $schema->create('user_role_assignments', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('user_id');
            $table->string('role_slug');
            $table->bigInteger('context_id');
            $table->boolean('is_active');
            $table->timestamp('expires_at')->nullable();
        });
        $this->database->table('authorization_contexts')->insert([
            ['id' => 1, 'type' => 'system', 'resource_id' => null, 'parent_context_id' => null],
            ['id' => 10, 'type' => 'organization', 'resource_id' => 7, 'parent_context_id' => 1],
        ]);
        $this->database->table('user_role_assignments')->insert([
            'user_id' => 8, 'role_slug' => 'document_reviewer', 'context_id' => 10, 'is_active' => true,
        ]);
        $this->database->table('users')->where('id', 8)->update(['current_organization_id' => 99]);
        $this->database->table('legal_workflow_steps')->where('id', 19)->update([
            'actor_type' => 'role', 'actor_reference' => 'document_reviewer',
        ]);
        Container::getInstance()->instance(AuthorizationService::class, new AuthorizationService(
            $this->createStub(RoleScanner::class),
            $this->createStub(PermissionResolver::class),
            $this->createStub(LoggingService::class),
        ));
        $step = LegalWorkflowStep::query()->findOrFail(19);
        $notifier = new LegalWorkflowApprovalNotifier(
            new LegalWorkflowActorResolver(documentAuthorizer: $this->createStub(LegalDocumentAuthorizer::class)),
            new LegalDocumentNotificationPublisher($this->notificationService),
        );

        $notifier->publishForStep($document, $step);
        $notifier->publishForStep($document, $step);

        self::assertSame(1, Notification::query()->forUser($recipient)->forOrganization(7)->forInterface(NotificationInterface::Admin)->count());
        self::assertSame('delivered', LegalDocumentNotificationDelivery::query()->sole()->status);
        self::assertSame(99, (int) $recipient->fresh()->current_organization_id);
        self::assertCount(1, $this->notificationService->queuedIds);
    }

    private function createSchema(): void
    {
        $schema = $this->database->schema();
        foreach (['notification_interface_cursors', 'notification_targets', 'notifications', 'legal_document_notification_deliveries', 'legal_workflow_steps', 'legal_workflow_instances', 'legal_archive_documents', 'user_role_assignments', 'authorization_contexts', 'organization_user', 'contracts', 'users'] as $table) {
            $schema->dropIfExists($table);
        }
        $schema->create('users', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->bigInteger('current_organization_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('organization_user', static function (Blueprint $table): void {
            $table->bigInteger('organization_id');
            $table->bigInteger('user_id');
            $table->boolean('is_active');
            $table->string('project_access_mode');
        });
        $schema->create('legal_workflow_instances', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->bigInteger('document_id');
            $table->bigInteger('organization_id');
            $table->string('status');
        });
        $schema->create('legal_workflow_steps', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->bigInteger('instance_id');
            $table->bigInteger('organization_id');
            $table->string('status');
            $table->string('actor_type');
            $table->string('actor_reference');
            $table->integer('assignment_revision');
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->bigInteger('id')->primary();
            $table->bigInteger('organization_id');
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });
        $schema->create('legal_document_notification_deliveries', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('document_id');
            $table->bigInteger('recipient_user_id');
            $table->string('delivery_key');
            $table->string('status');
            $table->uuid('notification_id')->nullable();
            $table->string('notification_type')->nullable();
            $table->jsonb('notification_payload')->nullable();
            $table->string('lease_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->integer('attempt_count')->default(0);
            $table->timestamps();
            $table->unique(['document_id', 'recipient_user_id', 'delivery_key']);
        });
        $schema->create('notifications', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->bigInteger('notifiable_id');
            $table->bigInteger('organization_id')->nullable();
            $table->string('notification_type');
            $table->string('priority');
            $table->jsonb('channels');
            $table->jsonb('delivery_status');
            $table->text('data');
            $table->jsonb('metadata');
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();
        });
        $schema->create('notification_targets', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('notification_id');
            $table->string('interface');
            $table->bigInteger('sequence')->generatedAs()->always();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'interface']);
        });
        $schema->create('notification_interface_cursors', static function (Blueprint $table): void {
            $table->bigInteger('recipient_user_id');
            $table->string('interface');
            $table->bigInteger('latest_sequence');
            $table->timestamps();
            $table->primary(['recipient_user_id', 'interface']);
        });
    }
}

final class CapturingLegalNotificationTransport extends NotificationService
{
    public array $queuedIds = [];

    public function dispatch(Notification $notification): void
    {
        $this->queuedIds[] = $notification->id;
    }
}
