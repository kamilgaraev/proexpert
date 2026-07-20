<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Access;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentAccessGrant;
use App\Domain\Authorization\Services\AuthorizationService;
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
            if ($this->authorization->can($user, $normalizedAbility->permission(), $context)) {
                return;
            }

            throw new AuthorizationException($this->message());
        }

        $grants = LegalDocumentAccessGrant::query()
            ->where('organization_id', $ownerOrganizationId)
            ->where('document_id', $documentId)
            ->where('subject_organization_id', $actorOrganizationId)
            ->whereNull('revoked_at')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->where(static function ($query) use ($user): void {
                $query->whereNull('subject_user_id')->orWhere('subject_user_id', (int) $user->id);
            })
            ->get(['abilities']);

        if ($grants->contains(static fn (LegalDocumentAccessGrant $grant): bool => $grant->allows($normalizedAbility))) {
            return;
        }

        throw new AuthorizationException($this->message());
    }

    public function scopeInternalQuery(Builder $query, User $user, int $organizationId, string $ability = 'view'): Builder
    {
        $normalizedAbility = LegalDocumentAbility::tryFrom($ability);
        if (
            $normalizedAbility === null
            || (int) $user->current_organization_id !== $organizationId
            || ! $this->belongsToOrganization($user, $organizationId)
            || ! $this->authorization->can($user, $normalizedAbility->permission(), ['organization_id' => $organizationId])
        ) {
            return $query->whereRaw('1 = 0');
        }
        $projectAccess = $this->projectAccess ?? Container::getInstance()->make(UserProjectAccessService::class);
        $projectIds = $projectAccess->queryAccessibleProjects($user, $organizationId)->select('projects.id');

        return $query->where(static function (Builder $scope) use ($projectIds): void {
            $scope->whereNull('primary_project_id')->orWhereIn('primary_project_id', $projectIds);
        });
    }

    public function grant(
        LegalArchiveDocument $document,
        User $actor,
        int $subjectOrganizationId,
        ?int $subjectUserId,
        array $abilities,
        ?CarbonInterface $expiresAt = null,
    ): LegalDocumentAccessGrant {
        $this->authorizeManagement($actor, $document);
        $abilities = array_values(array_unique(array_map('strval', $abilities)));
        sort($abilities, SORT_STRING);
        if (
            $subjectOrganizationId < 1
            || $subjectOrganizationId === (int) $document->organization_id
            || $abilities === []
            || array_diff($abilities, LegalDocumentAbility::values()) !== []
            || ($expiresAt !== null && ! $expiresAt->isFuture())
        ) {
            throw new DomainException('legal_document_access_grant_invalid');
        }
        if (! Organization::query()->whereKey($subjectOrganizationId)->exists()) {
            throw new DomainException('legal_document_access_subject_not_found');
        }
        if (
            $subjectUserId !== null
            && ! $this->connection()->table('organization_user')->where('organization_id', $subjectOrganizationId)
                ->where('user_id', $subjectUserId)->where('is_active', true)->exists()
        ) {
            throw new DomainException('legal_document_access_subject_not_found');
        }
        $connection = $this->connection();

        return $connection->transaction(function () use (
            $connection, $document, $actor, $subjectOrganizationId, $subjectUserId, $abilities, $expiresAt,
        ): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument(
                $connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            $existing = LegalDocumentAccessGrant::query()
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->where('subject_organization_id', $subjectOrganizationId)
                ->where('subject_user_id', $subjectUserId)
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
            $now = now();
            $inserted = LegalDocumentAccessGrant::query()->insertOrIgnore([
                'organization_id' => (int) $lockedDocument->organization_id,
                'document_id' => (int) $lockedDocument->id,
                'subject_organization_id' => $subjectOrganizationId,
                'subject_user_id' => $subjectUserId,
                'abilities' => json_encode($abilities, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'granted_by_user_id' => (int) $actor->id,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $grant = LegalDocumentAccessGrant::query()
                ->where('organization_id', (int) $lockedDocument->organization_id)
                ->where('document_id', (int) $lockedDocument->id)
                ->where('subject_organization_id', $subjectOrganizationId)
                ->where('subject_user_id', $subjectUserId)
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
                'subject_organization_id' => $subjectOrganizationId,
                'subject_user_id' => $subjectUserId,
                'abilities' => $abilities,
                'expires_at' => $expiresAt?->toAtomString(),
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
        $this->authorizeManagement($actor, $document);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 5000) {
            throw new DomainException('legal_document_access_revocation_reason_invalid');
        }
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $document, $grant, $actor, $reason): LegalDocumentAccessGrant {
            $lockedDocument = $this->lock()->lockDocument($connection, (int) $document->organization_id, (int) $document->id);
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
            $locked->forceFill([
                'revoked_at' => now(),
                'revoked_by_user_id' => (int) $actor->id,
                'revocation_reason' => $reason,
            ])->save();
            $this->audit()->record('access_revoked', $lockedDocument, $actor, ['grant_id' => (int) $locked->id]);

            return $locked;
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

    private function authorizeManagement(User $user, LegalArchiveDocument $document): void
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
}
