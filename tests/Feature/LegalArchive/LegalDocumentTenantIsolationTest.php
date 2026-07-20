<?php

declare(strict_types=1);

namespace Tests\Feature\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Project;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Access\LegalDocumentAccessSubject;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Workflow\LegalWorkflowAuthorization;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

final class LegalDocumentTenantIsolationTest extends TestCase
{
    private Capsule $database;

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
    }

    public function test_foreign_organization_is_denied_without_an_active_object_grant(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 20);
        $service = $this->service(true, true);

        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, $document, 'view');
    }

    public function test_active_grant_is_user_and_organization_scoped_and_abilities_are_exact(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 20);
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => 10,
            'document_id' => $document->id,
            'subject_organization_id' => 20,
            'subject_user_id' => 7,
            'subject_kind' => 'external_user',
            'abilities' => ['view', 'comment'],
            'granted_by_user_id' => 1,
        ]);

        $service = $this->service(false, true);
        $service->authorize($actor, $document, 'view');

        $this->expectException(AuthorizationException::class);
        $service->authorize($actor, $document, 'download');
    }

    public function test_expired_revoked_and_wrong_user_grants_fail_closed(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 20);
        foreach ([
            ['subject_user_id' => 8],
            ['subject_user_id' => 7, 'expires_at' => now()->subMinute()],
            ['subject_user_id' => 7, 'revoked_at' => now(), 'revoked_by_user_id' => 1, 'revocation_reason' => 'Доступ прекращён'],
        ] as $overrides) {
            LegalDocumentAccessGrant::query()->create([
                'organization_id' => 10,
                'document_id' => $document->id,
                'subject_organization_id' => 20,
                'subject_kind' => array_key_exists('subject_user_id', $overrides) && $overrides['subject_user_id'] !== null
                    ? 'external_user'
                    : 'external_org',
                'abilities' => ['view'],
                'granted_by_user_id' => 1,
                ...$overrides,
            ]);
        }

        $this->expectException(AuthorizationException::class);
        $this->service(false, true)->authorize($actor, $document, 'view');
    }

    public function test_internal_project_document_requires_project_access_in_addition_to_rbac(): void
    {
        $document = $this->document(10, 50);
        $actor = $this->actor(7, 10);

        $this->expectException(AuthorizationException::class);
        $this->service(true, false)->authorize($actor, $document, 'view');
    }

    public function test_restricted_internal_document_denies_owner_organization_without_explicit_object_grant(): void
    {
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $actor = $this->actor(7, 10);

        $this->expectException(AuthorizationException::class);
        $this->service(true, true)->authorize($actor, $document->refresh(), 'view');
    }

    public function test_restricted_document_accepts_exact_internal_user_grant(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 10,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $manager = $this->actor(1, 10);
        $actor = $this->actor(7, 10);
        $service = $this->service(true, true, new RecordingAccessAudit);

        $service->bootstrapCreator($document, (int) $manager->id);
        $service->grant($document, $manager, LegalDocumentAccessSubject::internalUser(10, 7), ['view']);
        $service->authorize($actor, $document->refresh(), 'view');

        self::assertTrue(true);
    }

    public function test_restricted_document_accepts_internal_role_grant_only_for_matching_role(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'secret'])->save();
        $manager = $this->actor(1, 10);
        $actor = $this->actor(7, 10);
        $grantService = $this->service(
            true,
            true,
            new RecordingAccessAudit,
            [],
            static fn (string $slug, int $organizationId): bool => $slug === 'legal_reviewer' && $organizationId === 10,
            static fn (): bool => false,
        );

        $grantService->bootstrapCreator($document, (int) $manager->id);
        $grantService->grant($document, $manager, LegalDocumentAccessSubject::internalRole(10, 'legal_reviewer'), ['view']);
        $this->service(true, true, new RecordingAccessAudit, ['legal_reviewer'])
            ->authorize($actor, $document->refresh(), 'view');

        try {
            $this->service(true, true, new RecordingAccessAudit, ['finance_reviewer'])
                ->authorize($actor, $document->refresh(), 'view');
            self::fail('A non-matching internal role received confidential dossier access.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
    }

    public function test_grant_and_revoke_are_atomic_audited_and_immediately_change_authorization(): void
    {
        $this->database->table('organizations')->insert([['id' => 10], ['id' => 20]]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 20,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $manager = $this->actor(1, 10);
        $recipient = $this->actor(7, 20);
        $audit = new RecordingAccessAudit;
        $service = $this->service(true, true, $audit);

        $grant = $service->grant($document, $manager, LegalDocumentAccessSubject::externalUser(20, 7), ['view', 'download'], CarbonImmutable::now()->addHour());
        $service->authorize($recipient, $document, 'download');
        $revoked = $service->revoke($document, $grant, $manager, 'Полномочия представителя прекращены');

        self::assertNotNull($revoked->revoked_at);
        self::assertSame(['access_granted', 'access_revoked'], $audit->events);
        $this->expectException(AuthorizationException::class);
        $service->authorize($recipient, $document, 'view');
    }

    public function test_external_subject_cannot_receive_access_management_ability(): void
    {
        $this->database->table('organizations')->insert([['id' => 10], ['id' => 20]]);
        $document = $this->document(10, null);

        $this->expectException(DomainException::class);
        $this->service(true, true, new RecordingAccessAudit)->grant(
            $document,
            $this->actor(1, 10),
            LegalDocumentAccessSubject::externalOrganization(20),
            ['view', 'manage'],
        );
    }

    public function test_expired_grant_is_closed_and_replaced_without_losing_history(): void
    {
        $this->database->table('organizations')->insert([['id' => 10], ['id' => 20]]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 20,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $expired = LegalDocumentAccessGrant::query()->create([
            'organization_id' => 10,
            'document_id' => $document->id,
            'subject_organization_id' => 20,
            'subject_user_id' => 7,
            'subject_kind' => 'external_user',
            'abilities' => ['view'],
            'granted_by_user_id' => 1,
            'expires_at' => CarbonImmutable::now()->subMinute(),
        ]);
        $audit = new RecordingAccessAudit;
        $service = $this->service(true, true, $audit);

        $replacement = $service->grant(
            $document,
            $this->actor(1, 10),
            LegalDocumentAccessSubject::externalUser(20, 7),
            ['view'],
            CarbonImmutable::now()->addHour(),
        );

        self::assertNotSame((int) $expired->id, (int) $replacement->id);
        self::assertNotNull($expired->fresh()?->revoked_at);
        self::assertSame(['access_expired', 'access_granted'], $audit->events);
    }

    public function test_external_workflow_decisions_use_approve_grant_but_internal_management_stays_closed(): void
    {
        $document = $this->document(10, null);
        $recipient = $this->actor(7, 20);
        $access = $this->createMock(LegalDocumentAuthorizer::class);
        $access->expects(self::exactly(3))->method('authorize')->with($recipient, $document, 'approve');
        $workflow = new LegalWorkflowAuthorization($access);

        self::assertTrue($workflow->can($recipient, $document, 'legal_archive.workflow.approve'));
        self::assertTrue($workflow->can($recipient, $document, 'legal_archive.workflow.reject'));
        self::assertTrue($workflow->can($recipient, $document, 'legal_archive.workflow.return'));
        self::assertFalse($workflow->can($recipient, $document, 'legal_archive.workflow.reassign'));
    }

    public function test_restricted_document_manager_cannot_bootstrap_access_by_granting_it_to_self(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $document->forceFill([
            'confidentiality_level' => 'restricted',
            'created_by_user_id' => 1,
            'owner_user_id' => 1,
        ])->save();
        $attacker = $this->actor(7, 10);

        $this->expectException(AuthorizationException::class);
        $this->service(true, true, new RecordingAccessAudit)->grant(
            $document,
            $attacker,
            LegalDocumentAccessSubject::internalUser(10, 7),
            ['view'],
        );
    }

    public function test_creator_bootstrap_allows_confidential_access_management_and_is_idempotent(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 10,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $document->forceFill([
            'confidentiality_level' => 'secret',
            'created_by_user_id' => 1,
            'owner_user_id' => 1,
        ])->save();
        $creator = $this->actor(1, 10);
        $recipient = $this->actor(7, 10);
        $service = $this->service(true, true, new RecordingAccessAudit);

        $first = $service->bootstrapCreator($document, (int) $creator->id);
        $second = $service->bootstrapCreator($document, (int) $creator->id);
        $grant = $service->grant(
            $document,
            $creator,
            LegalDocumentAccessSubject::internalUser(10, 7),
            ['view'],
        );

        self::assertSame((int) $first->id, (int) $second->id);
        self::assertContains('manage', $first->abilities);
        self::assertSame(7, (int) $grant->subject_user_id);
        $service->authorize($creator, $document, 'view');
        $service->authorize($recipient, $document, 'view');
    }

    public function test_expired_non_owner_management_grant_cannot_manage_confidential_access(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $this->database->table('organization_user')->insert([
            ['organization_id' => 10, 'user_id' => 7, 'is_active' => true],
            ['organization_id' => 10, 'user_id' => 2, 'is_active' => true],
        ]);
        $document = $this->document(10, null);
        $document->forceFill([
            'confidentiality_level' => 'restricted',
            'created_by_user_id' => 1,
            'owner_user_id' => 1,
        ])->save();
        $creator = $this->actor(1, 10);
        $service = $this->service(true, true, new RecordingAccessAudit);
        $creatorGrant = $service->bootstrapCreator($document, (int) $creator->id);
        $successorGrant = $service->grant(
            $document,
            $creator,
            LegalDocumentAccessSubject::internalUser(10, 7),
            ['manage', 'view'],
        );
        $service->revoke($document, $creatorGrant, $creator, 'Полномочия переданы');
        $this->database->table('legal_document_access_grants')->where('id', (int) $successorGrant->id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->expectException(AuthorizationException::class);
        $service->grant(
            $document,
            $this->actor(7, 10),
            LegalDocumentAccessSubject::internalUser(10, 2),
            ['view'],
        );
    }

    public function test_discovery_combines_internal_scope_with_active_external_view_grants(): void
    {
        $internal = $this->document(20, null);
        $external = $this->document(10, null);
        $commentOnly = $this->document(30, null);
        $actor = $this->actor(7, 20);
        foreach ([
            [$external, 'external_user', 7, ['view']],
            [$commentOnly, 'external_user', 7, ['comment']],
        ] as [$document, $subjectKind, $subjectUserId, $abilities]) {
            LegalDocumentAccessGrant::query()->create([
                'organization_id' => (int) $document->organization_id,
                'document_id' => (int) $document->id,
                'subject_kind' => $subjectKind,
                'subject_organization_id' => 20,
                'subject_user_id' => $subjectUserId,
                'abilities' => $abilities,
                'granted_by_user_id' => 1,
            ]);
        }

        $query = LegalArchiveDocument::query();
        $this->service(true, true)->scopeAccessibleQuery($query, $actor, 20, 'view');

        self::assertEqualsCanonicalizing([$internal->id, $external->id], $query->pluck('id')->all());
    }

    public function test_internal_null_confidentiality_is_discoverable_as_canonical_internal(): void
    {
        $document = $this->document(20, null);
        $document->forceFill(['confidentiality_level' => null])->save();
        $actor = $this->actor(7, 20);
        $query = LegalArchiveDocument::query();

        $this->service(true, true)->scopeAccessibleQuery($query, $actor, 20, 'view');

        self::assertSame([(int) $document->id], $query->pluck('id')->all());
        $refreshed = $document->refresh();
        self::assertSame('internal', $refreshed->confidentiality_level);
        $this->service(true, true)->authorize($actor, $refreshed, 'view');
    }

    public function test_confidential_discovery_uses_the_exact_requested_ability(): void
    {
        $document = $this->document(20, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $actor = $this->actor(7, 20);
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => 20,
            'document_id' => (int) $document->id,
            'subject_kind' => 'internal_user',
            'subject_organization_id' => 20,
            'subject_user_id' => 7,
            'abilities' => ['comment'],
            'granted_by_user_id' => 1,
        ]);

        $commentQuery = LegalArchiveDocument::query();
        $this->service(true, true)->scopeAccessibleQuery($commentQuery, $actor, 20, 'comment');
        $downloadQuery = LegalArchiveDocument::query();
        $this->service(true, true)->scopeAccessibleQuery($downloadQuery, $actor, 20, 'download');

        self::assertSame([(int) $document->id], $commentQuery->pluck('id')->all());
        self::assertSame([], $downloadQuery->pluck('id')->all());
        $this->service(true, true)->authorize($actor, $document, 'comment');
        $this->expectException(AuthorizationException::class);
        $this->service(true, true)->authorize($actor, $document, 'download');
    }

    public function test_internal_role_grant_rejects_unknown_or_non_assignable_role(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $manager = $this->actor(1, 10);
        $service = $this->service(true, true, new RecordingAccessAudit, [], static fn (string $slug, int $organizationId): bool => false);

        $this->expectException(DomainException::class);
        $service->grant($document, $manager, LegalDocumentAccessSubject::internalRole(10, 'missing_role'), ['view']);
    }

    public function test_actor_cannot_self_grant_through_an_internal_role(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $manager = $this->actor(1, 10);
        $service = $this->service(
            true,
            true,
            new RecordingAccessAudit,
            ['legal_reviewer'],
            static fn (): bool => true,
            static fn (User $actor, string $roleSlug): bool => (int) $actor->id === 1 && $roleSlug === 'legal_reviewer',
        );
        $service->bootstrapCreator($document, 1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_access_self_grant_forbidden');
        $service->grant($document, $manager, LegalDocumentAccessSubject::internalRole(10, 'legal_reviewer'), ['view']);
    }

    public function test_management_ability_cannot_be_assigned_to_a_role_principal(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $manager = $this->actor(1, 10);
        $service = $this->service(
            true,
            true,
            new RecordingAccessAudit,
            [],
            static fn (): bool => true,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_access_grant_invalid');
        $service->grant($document, $manager, LegalDocumentAccessSubject::internalRole(10, 'legal_reviewer'), ['manage']);
    }

    public function test_role_membership_resolution_failure_denies_role_grant(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $manager = $this->actor(1, 10);
        $service = $this->service(
            true,
            true,
            new RecordingAccessAudit,
            [],
            static fn (): bool => true,
            static function (): never {
                throw new \RuntimeException('role backend unavailable');
            },
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_access_role_membership_unavailable');
        $service->grant($document, $manager, LegalDocumentAccessSubject::internalRole(10, 'legal_reviewer'), ['view']);
    }

    public function test_role_grant_never_benefits_its_grantor_after_future_assignment(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $this->database->table('organization_user')->insert([
            ['organization_id' => 10, 'user_id' => 2, 'is_active' => true],
            ['organization_id' => 10, 'user_id' => 7, 'is_active' => true],
        ]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $owner = $this->actor(1, 10);
        $grantor = $this->actor(2, 10);
        $service = $this->service(
            true,
            true,
            new RecordingAccessAudit,
            [],
            static fn (): bool => true,
            static fn (): bool => false,
        );
        $service->bootstrapCreator($document, 1);
        $grantorManagement = $service->grant(
            $document,
            $owner,
            LegalDocumentAccessSubject::internalUser(10, 2),
            ['manage'],
        );
        $service->grant(
            $document,
            $grantor,
            LegalDocumentAccessSubject::internalRole(10, 'legal_reviewer'),
            ['view'],
        );
        $service->revoke($document, $grantorManagement, $owner, 'Полномочия отозваны');

        try {
            $this->service(true, true, new RecordingAccessAudit, ['legal_reviewer'])
                ->authorize($grantor, $document, 'view');
            self::fail('A role grant benefited its grantor after role assignment.');
        } catch (AuthorizationException) {
            self::assertTrue(true);
        }
        $this->service(true, true, new RecordingAccessAudit, ['legal_reviewer'])
            ->authorize($this->actor(7, 10), $document, 'view');
    }

    public function test_last_confidential_manager_cannot_be_revoked(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'secret'])->save();
        $manager = $this->actor(1, 10);
        $service = $this->service(true, true, new RecordingAccessAudit);
        $grant = $service->bootstrapCreator($document, 1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_access_last_manager');
        $service->revoke($document, $grant, $manager, 'Передача полномочий');
    }

    public function test_confidential_management_transfer_keeps_one_permanent_manager(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $this->database->table('organization_user')->insert([
            ['organization_id' => 10, 'user_id' => 7, 'is_active' => true],
            ['organization_id' => 10, 'user_id' => 8, 'is_active' => true],
        ]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $creator = $this->actor(1, 10);
        $successor = $this->actor(7, 10);
        $service = $this->service(true, true, new RecordingAccessAudit);
        $creatorGrant = $service->bootstrapCreator($document, 1);
        $service->grant(
            $document,
            $creator,
            LegalDocumentAccessSubject::internalUser(10, 7),
            ['manage', 'view'],
        );

        $service->revoke($document, $creatorGrant, $creator, 'Полномочия переданы');
        $successorGrant = $service->grant(
            $document,
            $successor,
            LegalDocumentAccessSubject::internalUser(10, 8),
            ['view'],
        );

        self::assertSame(8, (int) $successorGrant->subject_user_id);
        self::assertSame(1, LegalDocumentAccessGrant::query()
            ->where('document_id', (int) $document->id)
            ->whereNull('revoked_at')
            ->get()
            ->filter(static fn (LegalDocumentAccessGrant $grant): bool => $grant->allows(\App\Services\LegalArchive\Access\LegalDocumentAbility::MANAGE))
            ->count());
    }

    public function test_confidential_management_grant_cannot_expire(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $this->database->table('organization_user')->insert([
            'organization_id' => 10,
            'user_id' => 7,
            'is_active' => true,
        ]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $service = $this->service(true, true, new RecordingAccessAudit);
        $service->bootstrapCreator($document, 1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_document_access_management_must_be_permanent');
        $service->grant(
            $document,
            $this->actor(1, 10),
            LegalDocumentAccessSubject::internalUser(10, 7),
            ['manage', 'view'],
            CarbonImmutable::now()->addHour(),
        );
    }

    public function test_immutable_owner_can_recover_from_legacy_expired_management_grant(): void
    {
        $this->database->table('organizations')->insert(['id' => 10]);
        $document = $this->document(10, null);
        $document->forceFill(['confidentiality_level' => 'restricted'])->save();
        $service = $this->service(true, true, new RecordingAccessAudit);
        $expired = $service->bootstrapCreator($document, 1);
        $this->database->table('legal_document_access_grants')->where('id', (int) $expired->id)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $recovered = $service->recoverOwnerManagement($document, $this->actor(1, 10));

        self::assertNotSame((int) $expired->id, (int) $recovered->id);
        self::assertNull($recovered->expires_at);
        self::assertContains('manage', $recovered->abilities);
        self::assertNotNull($expired->fresh()?->revoked_at);
    }

    public function test_external_discovery_does_not_require_internal_archive_permission(): void
    {
        $this->document(20, null);
        $external = $this->document(10, null);
        $actor = $this->actor(7, 20);
        LegalDocumentAccessGrant::query()->create([
            'organization_id' => 10,
            'document_id' => (int) $external->id,
            'subject_kind' => 'external_org',
            'subject_organization_id' => 20,
            'subject_user_id' => null,
            'abilities' => ['view'],
            'granted_by_user_id' => 1,
        ]);

        $query = LegalArchiveDocument::query();
        $this->service(false, true)->scopeAccessibleQuery($query, $actor, 20, 'view');

        self::assertSame([(int) $external->id], $query->pluck('id')->all());
    }

    private function service(
        bool $rbacAllowed,
        bool $projectAllowed,
        ?LegalDocumentAudit $audit = null,
        array $roles = [],
        ?\Closure $roleResolver = null,
        ?\Closure $actorRoleMembershipResolver = null,
    ): LegalDocumentAccessService {
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('can')->willReturn($rbacAllowed);
        $authorization->method('getUserRoleSlugs')->willReturn($roles);
        $projectAccess = $this->createMock(UserProjectAccessService::class);
        $projectAccess->method('queryAccessibleProjects')->willReturn(Project::query());

        return new LegalDocumentAccessService(
            $authorization,
            static fn (User $user, int $organizationId): bool => (int) $user->current_organization_id === $organizationId,
            static fn (User $user, int $projectId, int $organizationId): bool => $projectAllowed,
            $projectAccess,
            $audit,
            $this->database->getConnection(),
            roleSubjectResolver: $roleResolver,
            actorRoleMembershipResolver: $actorRoleMembershipResolver,
        );
    }

    private function document(int $organizationId, ?int $projectId): LegalArchiveDocument
    {
        return LegalArchiveDocument::query()->create([
            'organization_id' => $organizationId,
            'primary_project_id' => $projectId,
            'title' => 'Досье',
            'created_by_user_id' => 1,
            'owner_user_id' => 1,
        ]);
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
        $schema->create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->softDeletes();
        });
        $schema->create('organization_user', static function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_active');
        });
        $schema->create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->boolean('is_archived')->default(false);
            $table->softDeletes();
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->string('title');
            $table->string('confidentiality_level')->nullable()->default('internal');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_document_access_grants', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('subject_organization_id');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->string('subject_kind')->nullable();
            $table->string('subject_role_slug')->nullable();
            $table->json('abilities');
            $table->unsignedBigInteger('granted_by_user_id');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
        });
    }
}

final class RecordingAccessAudit implements LegalDocumentAudit
{
    public array $events = [];

    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void
    {
        $this->events[] = $event;
    }

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}
