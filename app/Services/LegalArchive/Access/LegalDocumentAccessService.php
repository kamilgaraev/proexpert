<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\RoleScanner;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonInterface;
use Closure;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

final class LegalDocumentAccessService implements LegalDocumentAuthorizer
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ?Closure $membershipResolver = null,
        private readonly ?Closure $projectAccessResolver = null,
        private readonly ?UserProjectAccessService $projectAccess = null,
        private readonly ?LegalDocumentAudit $audit = null,
        private readonly ?ConnectionInterface $connection = null,
        private readonly ?LegalDocumentAggregateLock $aggregateLock = null,
        private readonly ?Closure $roleSubjectResolver = null,
        private readonly ?Closure $actorRoleMembershipResolver = null,
        private readonly ?Closure $managerEligibilityResolver = null,
    ) {}

    public function authorize(User $user, LegalArchiveDocument $document, string $ability): void
    {
        $normalizedAbility = LegalDocumentAbility::tryFrom($ability);
        $documentId = (int) $document->getKey();
        $ownerOrganizationId = (int) $document->organization_id;
        $actorOrganizationId = (int) $user->current_organization_id;
        if ($normalizedAbility === null || $documentId < 1 || $ownerOrganizationId < 1 || $actorOrganizationId < 1) {
            throw new AuthorizationException($this->message());
        }
        if (! $this->belongsToOrganization($user, $actorOrganizationId)) {
            throw new AuthorizationException($this->message());
        }

        if ($actorOrganizationId === $ownerOrganizationId) {
            $context = ['organization_id' => $ownerOrganizationId];
            if ($document->primary_project_id !== null) {
                $projectId = (int) $document->primary_project_id;
                $context['project_id'] = $projectId;
                if (! $this->canAccessProject($user, $projectId, $ownerOrganizationId)) {
                    throw new AuthorizationException($this->message());
                }
            }
            if (
                $this->authorization->can($user, $normalizedAbility->permission(), $context)
                && (! $this->requiresExplicitInternalGrant($document)
                    || $this->hasMatchingGrant($user, $document, $normalizedAbility))
            ) {
                return;
            }

            throw new AuthorizationException($this->message());
        }

        if ($this->hasMatchingGrant($user, $document, $normalizedAbility)) {
            return;
        }

        throw new AuthorizationException($this->message());
    }

    public function authorizePermission(User $user, LegalArchiveDocument $document, string $permission): void
    {
        $organizationId = (int) $document->organization_id;
        if (
            (int) $user->current_organization_id !== $organizationId
            || ! $this->belongsToOrganization($user, $organizationId)
            || ($document->primary_project_id !== null
                && ! $this->canAccessProject($user, (int) $document->primary_project_id, $organizationId))
            || ! $this->authorization->can($user, $permission, [
                'organization_id' => $organizationId,
                ...($document->primary_project_id === null ? [] : ['project_id' => (int) $document->primary_project_id]),
            ])
            || ($this->requiresExplicitInternalGrant($document)
                && ! $this->hasMatchingGrant($user, $document, LegalDocumentAbility::VIEW))
        ) {
            throw new AuthorizationException($this->message());
        }
    }

    private function hasMatchingGrant(
        User $user,
        LegalArchiveDocument $document,
        LegalDocumentAbility $ability,
    ): bool {
        $ownerOrganizationId = (int) $document->organization_id;
        $actorOrganizationId = (int) $user->current_organization_id;
        $roles = $actorOrganizationId === $ownerOrganizationId
            ? $this->authorization->getUserRoleSlugs($user, ['organization_id' => $ownerOrganizationId])
            : [];
        $grants = LegalDocumentAccessGrant::query()
            ->where('organization_id', $ownerOrganizationId)
            ->where('document_id', (int) $document->getKey())
            ->where('subject_organization_id', $actorOrganizationId)
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(static function ($query) use ($user, $ownerOrganizationId, $actorOrganizationId, $roles): void {
                if ($actorOrganizationId === $ownerOrganizationId) {
                    $query->where(static function ($internal) use ($user, $roles): void {
                        $internal->where(static function ($byUser) use ($user): void {
                            $byUser->where('subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_USER->value)
                                ->where('subject_user_id', (int) $user->id);
                        });
                        if ($roles !== []) {
                            $internal->orWhere(static function ($byRole) use ($user, $roles): void {
                                $byRole->where('subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_ROLE->value)
                                    ->whereIn('subject_role_slug', $roles)
                                    ->where('granted_by_user_id', '<>', (int) $user->id);
                            });
                        }
                    });

                    return;
                }
                $query->where(static function ($external) use ($user): void {
                    $external->where('subject_kind', LegalDocumentAccessSubjectKind::EXTERNAL_ORGANIZATION->value)
                        ->orWhere(static function ($byUser) use ($user): void {
                            $byUser->where('subject_kind', LegalDocumentAccessSubjectKind::EXTERNAL_USER->value)
                                ->where('subject_user_id', (int) $user->id);
                        });
                });
            })
            ->get(['abilities']);

        return $grants->contains(static fn (LegalDocumentAccessGrant $grant): bool => $grant->allows($ability));
    }

    public function scopeAccessibleQuery(Builder $query, User $user, int $organizationId, string $ability = 'view'): Builder
    {
        $normalizedAbility = LegalDocumentAbility::tryFrom($ability);
        if (
            $normalizedAbility === null
            || (int) $user->current_organization_id !== $organizationId
            || ! $this->belongsToOrganization($user, $organizationId)
        ) {
            return $query->whereRaw('1 = 0');
        }

        $canAccessInternal = $this->authorization->can(
            $user,
            $normalizedAbility->permission(),
            ['organization_id' => $organizationId],
        );
        $projectIds = $canAccessInternal
            ? ($this->projectAccess ?? Container::getInstance()->make(UserProjectAccessService::class))
                ->queryAccessibleProjects($user, $organizationId)
                ->select('projects.id')
            : null;
        $roleSlugs = $canAccessInternal
            ? $this->authorization->getUserRoleSlugs($user, ['organization_id' => $organizationId])
            : [];

        return $query->where(function (Builder $accessible) use (
            $user,
            $organizationId,
            $normalizedAbility,
            $canAccessInternal,
            $projectIds,
            $roleSlugs,
        ): void {
            if ($canAccessInternal && $projectIds instanceof Builder) {
                $accessible->where(function (Builder $internal) use ($user, $organizationId, $normalizedAbility, $projectIds, $roleSlugs): void {
                    $internal
                        ->where('legal_archive_documents.organization_id', $organizationId)
                        ->where(function (Builder $projects) use ($projectIds): void {
                            $projects->whereNull('legal_archive_documents.primary_project_id')
                                ->orWhereIn('legal_archive_documents.primary_project_id', $projectIds);
                        })
                        ->where(function (Builder $confidentiality) use ($user, $organizationId, $normalizedAbility, $roleSlugs): void {
                            $confidentiality
                                ->where(function (Builder $ordinary): void {
                                    $ordinary->whereNull('legal_archive_documents.confidentiality_level')
                                        ->orWhereNotIn('legal_archive_documents.confidentiality_level', ['restricted', 'secret']);
                                })
                                ->orWhereHas('accessGrants', function (Builder $grant) use ($user, $organizationId, $normalizedAbility, $roleSlugs): void {
                                    $grant
                                        ->whereColumn('legal_document_access_grants.organization_id', 'legal_archive_documents.organization_id')
                                        ->where('legal_document_access_grants.subject_organization_id', $organizationId)
                                        ->whereNull('legal_document_access_grants.revoked_at')
                                        ->where(function (Builder $expiry): void {
                                            $expiry->whereNull('legal_document_access_grants.expires_at')
                                                ->orWhere('legal_document_access_grants.expires_at', '>', now());
                                        })
                                        ->whereJsonContains('legal_document_access_grants.abilities', $normalizedAbility->value)
                                        ->where(function (Builder $subject) use ($user, $roleSlugs): void {
                                            $subject->where(function (Builder $byUser) use ($user): void {
                                                $byUser
                                                    ->where('legal_document_access_grants.subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_USER->value)
                                                    ->where('legal_document_access_grants.subject_user_id', (int) $user->id);
                                            });
                                            if ($roleSlugs !== []) {
                                                $subject->orWhere(function (Builder $byRole) use ($user, $roleSlugs): void {
                                                    $byRole
                                                        ->where('legal_document_access_grants.subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_ROLE->value)
                                                        ->whereIn('legal_document_access_grants.subject_role_slug', $roleSlugs)
                                                        ->where('legal_document_access_grants.granted_by_user_id', '<>', (int) $user->id);
                                                });
                                            }
                                        });
                                });
                        });
                });
            }

            $method = $canAccessInternal ? 'orWhereHas' : 'whereHas';
            $accessible->{$method}('accessGrants', function (Builder $grant) use ($user, $organizationId, $normalizedAbility): void {
                $grant
                    ->whereColumn('legal_document_access_grants.organization_id', 'legal_archive_documents.organization_id')
                    ->where('legal_document_access_grants.subject_organization_id', $organizationId)
                    ->whereNull('legal_document_access_grants.revoked_at')
                    ->where(function (Builder $expiry): void {
                        $expiry->whereNull('legal_document_access_grants.expires_at')
                            ->orWhere('legal_document_access_grants.expires_at', '>', now());
                    })
                    ->whereJsonContains('legal_document_access_grants.abilities', $normalizedAbility->value)
                    ->where(function (Builder $subject) use ($user): void {
                        $subject
                            ->where(function (Builder $byOrganization): void {
                                $byOrganization
                                    ->where('legal_document_access_grants.subject_kind', LegalDocumentAccessSubjectKind::EXTERNAL_ORGANIZATION->value)
                                    ->whereNull('legal_document_access_grants.subject_user_id');
                            })
                            ->orWhere(function (Builder $byUser) use ($user): void {
                                $byUser
                                    ->where('legal_document_access_grants.subject_kind', LegalDocumentAccessSubjectKind::EXTERNAL_USER->value)
                                    ->where('legal_document_access_grants.subject_user_id', (int) $user->id);
                            });
                    });
            });
        });
    }

    public function grant(
        LegalArchiveDocument $document,
        User $actor,
        LegalDocumentAccessSubject $subject,
        array $abilities,
        ?CarbonInterface $expiresAt = null,
    ): LegalDocumentAccessGrant {
        $this->authorizeManagementPreflight($actor, $document);
        $abilities = array_values(array_unique(array_map('strval', $abilities)));
        sort($abilities, SORT_STRING);
        if (
            ! $this->validSubject($document, $subject)
            || $abilities === []
            || array_diff($abilities, LegalDocumentAbility::values()) !== []
            || (in_array(LegalDocumentAbility::MANAGE->value, $abilities, true)
                && $subject->kind !== LegalDocumentAccessSubjectKind::INTERNAL_USER)
            || ($this->requiresExplicitInternalGrant($document)
                && in_array(LegalDocumentAbility::MANAGE->value, $abilities, true)
                && $expiresAt !== null)
            || ($expiresAt !== null && ! $expiresAt->isFuture())
        ) {
            if (
                $this->requiresExplicitInternalGrant($document)
                && in_array(LegalDocumentAbility::MANAGE->value, $abilities, true)
                && $expiresAt !== null
            ) {
                throw new DomainException('legal_document_access_management_must_be_permanent');
            }
            throw new DomainException('legal_document_access_grant_invalid');
        }
        if (! Organization::query()->whereKey($subject->organizationId)->exists()) {
            throw new DomainException('legal_document_access_subject_not_found');
        }
        if (
            $subject->userId !== null
            && ! $this->connection()->table('organization_user')->where('organization_id', $subject->organizationId)
                ->where('user_id', $subject->userId)->where('is_active', true)->exists()
        ) {
            throw new DomainException('legal_document_access_subject_not_found');
        }
        if (
            $subject->kind === LegalDocumentAccessSubjectKind::INTERNAL_ROLE
            && ! $this->roleSubjectExists((string) $subject->roleSlug, (int) $document->organization_id, $document)
        ) {
            throw new DomainException('legal_document_access_subject_not_found');
        }
        $connection = $this->connection();

        return $connection->transaction(function () use (
            $connection, $document, $actor, $subject, $abilities, $expiresAt,
        ): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument(
                $connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            $this->authorizeManagement($actor, $lockedDocument, true);
            if (
                $subject->kind === LegalDocumentAccessSubjectKind::INTERNAL_USER
                && in_array(LegalDocumentAbility::MANAGE->value, $abilities, true)
            ) {
                try {
                    $successor = $this->reloadActiveUserById((int) $subject->userId, $lockedDocument);
                } catch (Throwable) {
                    throw new DomainException('legal_document_access_successor_ineligible');
                }
                if (! $this->isEligibleManager($successor, $lockedDocument, false)) {
                    throw new DomainException('legal_document_access_successor_ineligible');
                }
            }
            $existing = LegalDocumentAccessGrant::query()
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->where('subject_kind', $subject->kind->value)
                ->where('subject_organization_id', $subject->organizationId)
                ->where('subject_user_id', $subject->userId)
                ->where('subject_role_slug', $subject->roleSlug)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if (
                $existing instanceof LegalDocumentAccessGrant
                && $existing->expires_at !== null
                && $existing->expires_at->isPast()
            ) {
                $existing->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => (int) $actor->id,
                    'revocation_reason' => $this->expiredReason(),
                ])->save();
                $this->audit()->record('access_expired', $lockedDocument, $actor, [
                    'grant_id' => (int) $existing->id,
                ]);
                $existing = null;
            }
            if ($existing instanceof LegalDocumentAccessGrant) {
                $existingAbilities = $existing->abilities ?? [];
                sort($existingAbilities, SORT_STRING);
                $sameExpiry = ($existing->expires_at === null && $expiresAt === null)
                    || ($existing->expires_at !== null && $expiresAt !== null && $existing->expires_at->equalTo($expiresAt));
                if ($existingAbilities === $abilities && $sameExpiry) {
                    return $existing;
                }
                throw new DomainException('legal_document_access_grant_conflict');
            }
            if (
                $subject->kind === LegalDocumentAccessSubjectKind::INTERNAL_USER
                && $subject->organizationId === (int) $actor->current_organization_id
                && $subject->userId === (int) $actor->id
            ) {
                throw new DomainException('legal_document_access_self_grant_forbidden');
            }
            if (
                $subject->kind === LegalDocumentAccessSubjectKind::INTERNAL_ROLE
                && $this->actorHasRole($actor, (string) $subject->roleSlug, $lockedDocument)
            ) {
                throw new DomainException('legal_document_access_self_grant_forbidden');
            }
            $now = now();
            $inserted = LegalDocumentAccessGrant::query()->insertOrIgnore([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'subject_kind' => $subject->kind->value,
                'subject_organization_id' => $subject->organizationId,
                'subject_user_id' => $subject->userId,
                'subject_role_slug' => $subject->roleSlug,
                'abilities' => json_encode($abilities, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'granted_by_user_id' => (int) $actor->id,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $grant = LegalDocumentAccessGrant::query()
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->where('subject_kind', $subject->kind->value)
                ->where('subject_organization_id', $subject->organizationId)
                ->where('subject_user_id', $subject->userId)
                ->where('subject_role_slug', $subject->roleSlug)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            if (! $grant instanceof LegalDocumentAccessGrant) {
                throw new DomainException('legal_document_access_grant_conflict');
            }
            if ($inserted === 0) {
                $persistedAbilities = $grant->abilities ?? [];
                sort($persistedAbilities, SORT_STRING);
                $sameExpiry = ($grant->expires_at === null && $expiresAt === null)
                    || ($grant->expires_at !== null && $expiresAt !== null && $grant->expires_at->equalTo($expiresAt));
                if ($persistedAbilities === $abilities && $sameExpiry) {
                    return $grant;
                }
                throw new DomainException('legal_document_access_grant_conflict');
            }
            $this->audit()->record('access_granted', $lockedDocument, $actor, [
                'grant_id' => (int) $grant->id,
                'subject_kind' => $subject->kind->value,
                'subject_organization_id' => $subject->organizationId,
                'subject_user_id' => $subject->userId,
                'subject_role_slug' => $subject->roleSlug,
                'abilities' => $abilities,
                'expires_at' => $expiresAt?->toAtomString(),
            ]);

            return $grant;
        });
    }

    public function bootstrapCreator(LegalArchiveDocument $document, int $actorId): LegalDocumentAccessGrant
    {
        if (
            $actorId < 1
            || (int) $document->created_by_user_id !== $actorId
            || (int) $document->owner_user_id !== $actorId
        ) {
            throw new AuthorizationException($this->message());
        }
        $abilities = [
            LegalDocumentAbility::COMMENT->value,
            LegalDocumentAbility::DOWNLOAD->value,
            LegalDocumentAbility::MANAGE->value,
            LegalDocumentAbility::VIEW->value,
        ];
        sort($abilities, SORT_STRING);
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $document, $actorId, $abilities): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument(
                $connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            $query = LegalDocumentAccessGrant::query()
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->where('subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_USER->value)
                ->where('subject_organization_id', (int) $lockedDocument->organization_id)
                ->where('subject_user_id', $actorId)
                ->whereNull('subject_role_slug')
                ->whereNull('revoked_at');
            $existing = (clone $query)->lockForUpdate()->first();
            if ($existing instanceof LegalDocumentAccessGrant) {
                $persisted = $existing->abilities ?? [];
                sort($persisted, SORT_STRING);
                if ($persisted === $abilities && $existing->expires_at === null) {
                    return $existing;
                }
                throw new DomainException('legal_document_access_grant_conflict');
            }
            $now = now();
            LegalDocumentAccessGrant::query()->insertOrIgnore([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'subject_kind' => LegalDocumentAccessSubjectKind::INTERNAL_USER->value,
                'subject_organization_id' => (int) $lockedDocument->organization_id,
                'subject_user_id' => $actorId,
                'subject_role_slug' => null,
                'abilities' => json_encode($abilities, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'granted_by_user_id' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $grant = (clone $query)->lockForUpdate()->first();
            if (! $grant instanceof LegalDocumentAccessGrant) {
                throw new DomainException('legal_document_access_grant_conflict');
            }
            $persisted = $grant->abilities ?? [];
            sort($persisted, SORT_STRING);
            if ($persisted !== $abilities || $grant->expires_at !== null) {
                throw new DomainException('legal_document_access_grant_conflict');
            }
            $this->audit()->recordForActorId('access_bootstrapped', $lockedDocument, $actorId, [
                'grant_id' => (int) $grant->id,
                'abilities' => $abilities,
            ]);

            return $grant;
        });
    }

    public function revoke(
        LegalArchiveDocument $document,
        LegalDocumentAccessGrant $grant,
        User $actor,
        string $reason,
    ): LegalDocumentAccessGrant {
        $this->authorizeManagementPreflight($actor, $document);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw new DomainException('legal_document_access_revocation_reason_invalid');
        }
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $document, $grant, $actor, $reason): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument($connection, (int) $document->organization_id, (int) $document->id);
            $this->authorizeManagement($actor, $lockedDocument, true);
            $locked = LegalDocumentAccessGrant::query()
                ->whereKey((int) $grant->id)
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof LegalDocumentAccessGrant) {
                throw new DomainException('legal_document_access_grant_not_found');
            }
            if ($locked->revoked_at !== null) {
                return $locked;
            }
            if (
                $this->requiresExplicitInternalGrant($lockedDocument)
                && $this->isInternalManagementGrant($locked)
                && ! $this->hasOtherActiveInternalManager($lockedDocument, (int) $locked->id)
            ) {
                throw new DomainException('legal_document_access_last_manager');
            }
            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => (int) $actor->id,
                'revocation_reason' => $reason,
            ])->save();
            $this->audit()->record('access_revoked', $lockedDocument, $actor, ['grant_id' => (int) $locked->id]);

            return $locked;
        });
    }

    public function recoverOwnerManagement(
        LegalArchiveDocument $document,
        User $actor,
    ): LegalDocumentAccessGrant {
        $this->authorizeManagementBoundary($actor, $document);
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $document, $actor): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument(
                $connection,
                (int) $document->organization_id,
                (int) $document->id,
            );

            $lockedActor = $this->reloadActiveUser($actor, $lockedDocument);
            $this->authorizeManagementBoundary($lockedActor, $lockedDocument);

            return $this->recoverOwnerManagementLocked($lockedDocument, $lockedActor);
        });
    }

    public function recoverManagementAsSecurityAdministrator(
        LegalArchiveDocument $document,
        User $actor,
        int $successorUserId,
    ): LegalDocumentAccessGrant {
        if ($successorUserId < 1) {
            throw new DomainException('legal_document_access_subject_not_found');
        }
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $document, $actor, $successorUserId): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument(
                $connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            $lockedActor = $this->reloadActiveUser($actor, $lockedDocument);
            $this->authorizeSecurityRecovery($lockedActor, $lockedDocument);
            if (
                ! $this->requiresExplicitInternalGrant($lockedDocument)
                || $this->hasEffectiveInternalManager($lockedDocument)
            ) {
                throw new DomainException('legal_document_access_recovery_not_required');
            }
            $successor = $this->reloadActiveUserById($successorUserId, $lockedDocument);
            if (! $this->isEligibleManager($successor, $lockedDocument, false)) {
                throw new DomainException('legal_document_access_successor_ineligible');
            }
            $replaced = LegalDocumentAccessGrant::query()
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->where('subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_USER->value)
                ->where('subject_organization_id', (int) $lockedDocument->organization_id)
                ->where('subject_user_id', (int) $successor->id)
                ->whereNull('subject_role_slug')
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get();
            foreach ($replaced as $replacedGrant) {
                $replacedGrant->forceFill([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => (int) $lockedActor->id,
                    'revocation_reason' => $this->securityRecoveryReason(),
                ])->save();
                $this->audit()->record('access_security_recovery_replaced', $lockedDocument, $lockedActor, [
                    'grant_id' => (int) $replacedGrant->id,
                    'successor_user_id' => (int) $successor->id,
                ]);
            }
            $abilities = [
                LegalDocumentAbility::COMMENT->value,
                LegalDocumentAbility::DOWNLOAD->value,
                LegalDocumentAbility::MANAGE->value,
                LegalDocumentAbility::VIEW->value,
            ];
            sort($abilities, SORT_STRING);
            $grant = LegalDocumentAccessGrant::query()->create([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'subject_kind' => LegalDocumentAccessSubjectKind::INTERNAL_USER->value,
                'subject_organization_id' => (int) $lockedDocument->organization_id,
                'subject_user_id' => (int) $successor->id,
                'subject_role_slug' => null,
                'abilities' => $abilities,
                'granted_by_user_id' => (int) $lockedActor->id,
                'expires_at' => null,
            ]);
            $this->audit()->record('access_security_recovered', $lockedDocument, $lockedActor, [
                'grant_id' => (int) $grant->id,
                'successor_user_id' => (int) $successor->id,
                'abilities' => $abilities,
            ]);

            return $grant;
        });
    }

    private function belongsToOrganization(User $user, int $organizationId): bool
    {
        if ($this->membershipResolver !== null) {
            return (bool) ($this->membershipResolver)($user, $organizationId);
        }

        return $user->organizations()
            ->where('organizations.id', $organizationId)
            ->wherePivot('is_active', true)
            ->exists();
    }

    private function requiresExplicitInternalGrant(LegalArchiveDocument $document): bool
    {
        return in_array((string) $document->confidentiality_level, ['restricted', 'secret'], true);
    }

    private function validSubject(LegalArchiveDocument $document, LegalDocumentAccessSubject $subject): bool
    {
        $ownerOrganizationId = (int) $document->organization_id;
        if ($subject->organizationId < 1) {
            return false;
        }

        return match ($subject->kind) {
            LegalDocumentAccessSubjectKind::INTERNAL_USER => $subject->organizationId === $ownerOrganizationId
                && $subject->userId !== null && $subject->userId > 0 && $subject->roleSlug === null,
            LegalDocumentAccessSubjectKind::INTERNAL_ROLE => $subject->organizationId === $ownerOrganizationId
                && $subject->userId === null && $subject->roleSlug !== null && $subject->roleSlug !== ''
                && mb_strlen($subject->roleSlug) <= 191,
            LegalDocumentAccessSubjectKind::EXTERNAL_ORGANIZATION => $subject->organizationId !== $ownerOrganizationId
                && $subject->userId === null && $subject->roleSlug === null,
            LegalDocumentAccessSubjectKind::EXTERNAL_USER => $subject->organizationId !== $ownerOrganizationId
                && $subject->userId !== null && $subject->userId > 0 && $subject->roleSlug === null,
        };
    }

    private function authorizeManagementBoundary(User $user, LegalArchiveDocument $document): void
    {
        $organizationId = (int) $document->organization_id;
        if (
            (int) $user->current_organization_id !== $organizationId
            || ! $this->belongsToOrganization($user, $organizationId)
            || ($document->primary_project_id !== null
                && ! $this->canAccessProject($user, (int) $document->primary_project_id, $organizationId))
            || ! $this->authorization->can($user, 'legal_archive.external_access.manage', [
                'organization_id' => $organizationId,
                ...($document->primary_project_id === null ? [] : ['project_id' => (int) $document->primary_project_id]),
            ])
        ) {
            throw new AuthorizationException($this->message());
        }
    }

    private function authorizeManagementPreflight(User $user, LegalArchiveDocument $document): void
    {
        $this->authorizeManagementBoundary($user, $document);
        if (
            $this->requiresExplicitInternalGrant($document)
            && ! $this->hasMatchingGrant($user, $document, LegalDocumentAbility::MANAGE)
            && ! $this->isImmutableOwner($user, $document)
        ) {
            throw new AuthorizationException($this->message());
        }
    }

    private function authorizeManagement(
        User $user,
        LegalArchiveDocument $document,
        bool $allowOwnerRecovery = false,
    ): void {
        $this->authorizeManagementBoundary($user, $document);
        if (
            ! $this->requiresExplicitInternalGrant($document)
            || $this->hasMatchingGrant($user, $document, LegalDocumentAbility::MANAGE)
        ) {
            return;
        }
        if ($allowOwnerRecovery && $this->isImmutableOwner($user, $document)) {
            $lockedUser = $this->reloadActiveUser($user, $document);
            $this->authorizeManagementBoundary($lockedUser, $document);
            $this->recoverOwnerManagementLocked($document, $lockedUser);

            return;
        }

        throw new AuthorizationException($this->message());
    }

    private function recoverOwnerManagementLocked(
        LegalArchiveDocument $document,
        User $actor,
    ): LegalDocumentAccessGrant {
        if (! $this->requiresExplicitInternalGrant($document) || ! $this->isImmutableOwner($actor, $document)) {
            throw new AuthorizationException($this->message());
        }
        $activeOwnerGrants = LegalDocumentAccessGrant::query()
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->where('subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_USER->value)
            ->where('subject_organization_id', (int) $document->organization_id)
            ->where('subject_user_id', (int) $actor->id)
            ->whereNull('subject_role_slug')
            ->whereNull('revoked_at')
            ->lockForUpdate()
            ->get();
        foreach ($activeOwnerGrants as $grant) {
            if ($grant->allows(LegalDocumentAbility::MANAGE) && $grant->expires_at === null) {
                return $grant;
            }
            $grant->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => (int) $actor->id,
                'revocation_reason' => $this->ownerRecoveryReason(),
            ])->save();
            $this->audit()->record('access_recovery_replaced', $document, $actor, [
                'grant_id' => (int) $grant->id,
            ]);
        }
        $abilities = [
            LegalDocumentAbility::COMMENT->value,
            LegalDocumentAbility::DOWNLOAD->value,
            LegalDocumentAbility::MANAGE->value,
            LegalDocumentAbility::VIEW->value,
        ];
        sort($abilities, SORT_STRING);
        $now = now();
        $grant = LegalDocumentAccessGrant::query()->create([
            'organization_id' => (int) $document->organization_id,
            'document_id' => (int) $document->id,
            'subject_kind' => LegalDocumentAccessSubjectKind::INTERNAL_USER->value,
            'subject_organization_id' => (int) $document->organization_id,
            'subject_user_id' => (int) $actor->id,
            'subject_role_slug' => null,
            'abilities' => $abilities,
            'granted_by_user_id' => (int) $actor->id,
            'expires_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->audit()->record('access_recovered', $document, $actor, [
            'grant_id' => (int) $grant->id,
            'abilities' => $abilities,
        ]);

        return $grant;
    }

    private function isImmutableOwner(User $actor, LegalArchiveDocument $document): bool
    {
        return (int) $document->created_by_user_id === (int) $actor->id
            && (int) $document->owner_user_id === (int) $actor->id;
    }

    private function isInternalManagementGrant(LegalDocumentAccessGrant $grant): bool
    {
        return in_array($grant->subject_kind, [
            LegalDocumentAccessSubjectKind::INTERNAL_USER->value,
            LegalDocumentAccessSubjectKind::INTERNAL_ROLE->value,
        ], true) && $grant->allows(LegalDocumentAbility::MANAGE);
    }

    private function hasOtherActiveInternalManager(LegalArchiveDocument $document, int $excludedGrantId): bool
    {
        $grants = LegalDocumentAccessGrant::query()
            ->where('organization_id', (int) $document->organization_id)
            ->where('document_id', (int) $document->id)
            ->whereKeyNot($excludedGrantId)
            ->where('subject_kind', LegalDocumentAccessSubjectKind::INTERNAL_USER->value)
            ->whereNull('revoked_at')
            ->whereNull('expires_at')
            ->whereJsonContains('abilities', LegalDocumentAbility::MANAGE->value)
            ->lockForUpdate()
            ->get();

        return $grants->contains(
            fn (LegalDocumentAccessGrant $grant): bool => $this->isEffectiveManagerGrant($grant, $document),
        );
    }

    private function hasEffectiveInternalManager(LegalArchiveDocument $document): bool
    {
        return $this->hasOtherActiveInternalManager($document, 0);
    }

    private function isEffectiveManagerGrant(
        LegalDocumentAccessGrant $grant,
        LegalArchiveDocument $document,
    ): bool {
        if (
            $grant->subject_kind !== LegalDocumentAccessSubjectKind::INTERNAL_USER->value
            || $grant->subject_user_id === null
            || $grant->expires_at !== null
            || ! $grant->allows(LegalDocumentAbility::MANAGE)
        ) {
            return false;
        }

        try {
            $user = $this->reloadActiveUserById((int) $grant->subject_user_id, $document);

            return $this->isEligibleManager($user, $document, true);
        } catch (Throwable) {
            return false;
        }
    }

    private function isEligibleManager(User $user, LegalArchiveDocument $document, bool $requireObjectGrant): bool
    {
        try {
            if ($this->managerEligibilityResolver !== null) {
                $eligible = (bool) ($this->managerEligibilityResolver)($user, $document);
            } else {
                $activeMembership = $this->membershipResolver !== null
                    ? $this->belongsToOrganization($user, (int) $document->organization_id)
                    : ($this->lockActiveMembership($user, $document)
                        && $this->belongsToOrganization($user, (int) $document->organization_id));
                $eligible = $activeMembership
                    && ($document->primary_project_id === null
                        || $this->canAccessProject(
                            $user,
                            (int) $document->primary_project_id,
                            (int) $document->organization_id,
                        ))
                    && $this->authorization->can($user, 'legal_archive.external_access.manage', [
                        'organization_id' => (int) $document->organization_id,
                        ...($document->primary_project_id === null
                            ? []
                            : ['project_id' => (int) $document->primary_project_id]),
                    ]);
            }

            return $eligible
                && (! $requireObjectGrant
                    || $this->hasMatchingGrant($user, $document, LegalDocumentAbility::MANAGE));
        } catch (Throwable) {
            return false;
        }
    }

    private function reloadActiveUser(User $user, LegalArchiveDocument $document): User
    {
        return $this->reloadActiveUserById((int) $user->id, $document);
    }

    private function reloadActiveUserById(int $userId, LegalArchiveDocument $document): User
    {
        $user = (new User)->setConnection($document->getConnectionName())->newQuery()
            ->whereKey($userId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();
        if (! $user instanceof User) {
            throw new AuthorizationException($this->message());
        }
        $user->current_organization_id = (int) $document->organization_id;

        return $user;
    }

    private function lockActiveMembership(User $user, LegalArchiveDocument $document): bool
    {
        return $this->connection()->table('organization_user')
            ->where('organization_id', (int) $document->organization_id)
            ->where('user_id', (int) $user->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->exists();
    }

    private function authorizeSecurityRecovery(User $user, LegalArchiveDocument $document): void
    {
        $organizationId = (int) $document->organization_id;
        if (
            ! $this->belongsToOrganization($user, $organizationId)
            || ($document->primary_project_id !== null
                && ! $this->canAccessProject($user, (int) $document->primary_project_id, $organizationId))
            || ! $this->authorization->can($user, 'legal_archive.security_recovery.manage', [
                'organization_id' => $organizationId,
                ...($document->primary_project_id === null
                    ? []
                    : ['project_id' => (int) $document->primary_project_id]),
            ])
        ) {
            throw new AuthorizationException($this->message());
        }
    }

    private function actorHasRole(User $actor, string $roleSlug, LegalArchiveDocument $document): bool
    {
        try {
            if ($this->actorRoleMembershipResolver !== null) {
                return (bool) ($this->actorRoleMembershipResolver)($actor, $roleSlug, $document);
            }
            $context = AuthorizationContext::getOrganizationContext((int) $document->organization_id);
            if (! $context instanceof AuthorizationContext) {
                throw new DomainException('legal_document_access_role_membership_unavailable');
            }

            return (new UserRoleAssignment)->setConnection($document->getConnectionName())->newQuery()
                ->where('user_id', (int) $actor->id)
                ->where('role_slug', $roleSlug)
                ->where('context_id', (int) $context->id)
                ->active()
                ->exists();
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DomainException('legal_document_access_role_membership_unavailable');
        }
    }

    private function roleSubjectExists(
        string $roleSlug,
        int $organizationId,
        LegalArchiveDocument $document,
    ): bool {
        if ($this->roleSubjectResolver !== null) {
            return (bool) ($this->roleSubjectResolver)($roleSlug, $organizationId);
        }
        $role = Container::getInstance()->make(RoleScanner::class)->getRole($roleSlug);
        if (is_array($role)) {
            $context = (string) ($role['context'] ?? '');
            $interface = (string) ($role['interface'] ?? '');

            return ($role['assignable'] ?? true) !== false
                && $context === 'organization'
                && $interface === 'admin';
        }

        return (new OrganizationCustomRole)->setConnection($document->getConnectionName())->newQuery()
            ->where('organization_id', $organizationId)
            ->where('slug', $roleSlug)
            ->active()
            ->whereJsonContains('interface_access', 'admin')
            ->exists();
    }

    private function connection(): ConnectionInterface
    {
        return $this->connection ?? Container::getInstance()->make(ConnectionInterface::class);
    }

    private function lock(): LegalDocumentAggregateLock
    {
        return $this->aggregateLock ?? new LegalDocumentAggregateLock;
    }

    private function audit(): LegalDocumentAudit
    {
        return $this->audit ?? Container::getInstance()->make(LegalDocumentAudit::class);
    }

    private function canAccessProject(User $user, int $projectId, int $organizationId): bool
    {
        if ($this->projectAccessResolver !== null) {
            return (bool) ($this->projectAccessResolver)($user, $projectId, $organizationId);
        }

        $service = $this->projectAccess ?? Container::getInstance()->make(UserProjectAccessService::class);

        return $service->queryAccessibleProjects($user, $organizationId)->whereKey($projectId)->exists();
    }

    private function message(): string
    {
        return Container::getInstance()->bound('translator')
            ? trans_message('legal_archive.messages.document_not_found')
            : 'legal_archive.messages.document_not_found';
    }

    private function expiredReason(): string
    {
        return Container::getInstance()->bound('translator')
            ? trans_message('legal_archive.messages.access_expired_reason')
            : 'legal_archive.messages.access_expired_reason';
    }

    private function ownerRecoveryReason(): string
    {
        return Container::getInstance()->bound('translator')
            ? trans_message('legal_archive.messages.access_owner_recovery_reason')
            : 'legal_archive.messages.access_owner_recovery_reason';
    }

    private function securityRecoveryReason(): string
    {
        return Container::getInstance()->bound('translator')
            ? trans_message('legal_archive.messages.access_security_recovery_reason')
            : 'legal_archive.messages.access_security_recovery_reason';
    }
}
