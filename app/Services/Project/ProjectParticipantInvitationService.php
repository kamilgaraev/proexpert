<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Mail\ProjectParticipantInvitationMail;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProjectParticipantInvitationService
{
    private const DEFAULT_TTL_DAYS = 7;

    public function __construct(
        private readonly ProjectParticipantService $projectParticipantService
    ) {}

    public function list(Project $project): Collection
    {
        $this->expirePendingInvitations($project);

        return ProjectParticipantInvitation::query()
            ->with([
                'invitedOrganization:id,name,tax_number,email,phone',
                'acceptedBy:id,name',
                'invitedBy:id,name',
                'cancelledBy:id,name',
            ])
            ->where('project_id', $project->id)
            ->whereIn('status', [
                ProjectParticipantInvitation::STATUS_PENDING,
                ProjectParticipantInvitation::STATUS_EXPIRED,
            ])
            ->latest()
            ->get();
    }

    public function create(Project $project, int $organizationId, User $user, array $payload): ProjectParticipantInvitation
    {
        $this->expirePendingInvitations($project);

        $role = ProjectOrganizationRole::from((string) $payload['role']);
        $invitedOrganizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
        $invitedOrganization = $invitedOrganizationId !== null
            ? Organization::find($invitedOrganizationId)
            : null;

        if ($invitedOrganizationId !== null && ! $invitedOrganization instanceof Organization) {
            throw new BusinessLogicException('Организация для приглашения не найдена.', 404);
        }

        if ($invitedOrganization instanceof Organization) {
            if ($project->organizations()->where('organizations.id', $invitedOrganizationId)->exists()) {
                throw new BusinessLogicException('Организация уже участвует в проекте.', 409);
            }

            $this->projectParticipantService->assertCanAssumeRole($invitedOrganization, $role);
            $this->projectParticipantService->enforceUniqueCustomer($project, $role, $invitedOrganizationId);
        } elseif ($role === ProjectOrganizationRole::CUSTOMER) {
            $this->projectParticipantService->enforceUniqueCustomer($project, $role);
        }

        $email = isset($payload['email']) ? trim((string) $payload['email']) : null;
        $organizationName = isset($payload['organization_name']) ? trim((string) $payload['organization_name']) : null;

        if ($invitedOrganizationId === null && ($email === null || $organizationName === null)) {
            throw new BusinessLogicException(
                'Для незарегистрированного участника обязательны email и название организации.',
                422
            );
        }

        $existingPending = ProjectParticipantInvitation::query()
            ->pending()
            ->where('project_id', $project->id)
            ->when(
                $invitedOrganizationId !== null,
                fn ($query) => $query->where('invited_organization_id', $invitedOrganizationId)
            )
            ->when(
                $invitedOrganizationId === null && $email !== null,
                fn ($query) => $query->where('email', $email)
            )
            ->first();

        if ($existingPending instanceof ProjectParticipantInvitation) {
            throw new BusinessLogicException('Активное приглашение для этого участника уже существует.', 409);
        }

        $invitation = ProjectParticipantInvitation::create([
            'project_id' => $project->id,
            'organization_id' => $organizationId,
            'invited_by_user_id' => $user->id,
            'invited_organization_id' => $invitedOrganizationId,
            'role' => $role->value,
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
            'status_reason' => null,
            'organization_name' => $organizationName,
            'inn' => $payload['inn'] ?? null,
            'email' => $email,
            'contact_name' => $payload['contact_name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'message' => $payload['message'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
            'expires_at' => now()->addDays(self::DEFAULT_TTL_DAYS),
        ]);

        $this->sendInvitationEmail($invitation);

        return $this->freshInvitation($invitation);
    }

    public function cancel(Project $project, ProjectParticipantInvitation $invitation, User $user): ProjectParticipantInvitation
    {
        $this->assertInvitationBelongsToProject($project, $invitation);
        $this->expirePendingInvitations(invitation: $invitation);
        $invitation->refresh();

        if ($invitation->isAccepted()) {
            throw new BusinessLogicException('Принятое приглашение нельзя отменить.', 409);
        }

        if ($invitation->isCancelled()) {
            return $this->freshInvitation($invitation);
        }

        $invitation->update([
            'status' => ProjectParticipantInvitation::STATUS_CANCELLED,
            'status_reason' => 'cancelled',
            'cancelled_at' => now(),
            'cancelled_by_user_id' => $user->id,
        ]);

        return $this->freshInvitation($invitation);
    }

    public function resend(Project $project, ProjectParticipantInvitation $invitation, User $user): ProjectParticipantInvitation
    {
        $this->assertInvitationBelongsToProject($project, $invitation);
        $this->expirePendingInvitations(invitation: $invitation);
        $invitation->refresh();

        if ($invitation->isAccepted()) {
            throw new BusinessLogicException('Принятое приглашение нельзя переотправить.', 409);
        }

        if ($invitation->invitedOrganization instanceof Organization) {
            if ($project->organizations()->where('organizations.id', $invitation->invitedOrganization->id)->exists()) {
                throw new BusinessLogicException('Организация уже участвует в проекте.', 409);
            }

            $this->projectParticipantService->assertCanAssumeRole(
                $invitation->invitedOrganization,
                $invitation->roleEnum()
            );
            $this->projectParticipantService->enforceUniqueCustomer(
                $project,
                $invitation->roleEnum(),
                $invitation->invitedOrganization->id
            );
        } elseif ($invitation->roleEnum() === ProjectOrganizationRole::CUSTOMER) {
            $this->projectParticipantService->enforceUniqueCustomer($project, $invitation->roleEnum());
        }

        $expiresAt = ($invitation->expires_at !== null && $invitation->expires_at->isFuture())
            ? $invitation->expires_at->copy()->addDays(self::DEFAULT_TTL_DAYS)
            : now()->addDays(self::DEFAULT_TTL_DAYS);

        $invitation->update([
            'token' => Str::random(64),
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
            'status_reason' => null,
            'expires_at' => $expiresAt,
            'resent_at' => now(),
            'cancelled_at' => null,
            'cancelled_by_user_id' => null,
            'accepted_at' => null,
            'accepted_by_user_id' => null,
            'accepted_organization_id_snapshot' => null,
        ]);

        Log::info('project.participant_invitation.resent', [
            'invitation_id' => $invitation->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
        ]);

        $this->sendInvitationEmail($invitation);

        return $this->freshInvitation($invitation);
    }

    public function updateRole(
        Project $project,
        ProjectParticipantInvitation $invitation,
        string $roleValue
    ): ProjectParticipantInvitation {
        $this->assertInvitationBelongsToProject($project, $invitation);
        $this->expirePendingInvitations(invitation: $invitation);
        $invitation->refresh();

        if (! $invitation->isPending()) {
            throw new BusinessLogicException(trans_message('project.invitation_role_not_pending'), 409);
        }

        $role = ProjectOrganizationRole::from($roleValue);

        if ($invitation->invitedOrganization instanceof Organization) {
            $this->projectParticipantService->assertCanAssumeRole($invitation->invitedOrganization, $role);
            $this->projectParticipantService->enforceUniqueCustomer(
                $project,
                $role,
                $invitation->invitedOrganization->id
            );
        } elseif ($role === ProjectOrganizationRole::CUSTOMER) {
            $this->projectParticipantService->enforceUniqueCustomer($project, $role);
        }

        $invitation->update([
            'role' => $role->value,
        ]);

        return $this->freshInvitation($invitation);
    }

    public function acceptByToken(string $token, User $user, Organization $organization): ProjectParticipantInvitation
    {
        return $this->acceptByTokenUsingIdentityPolicy($token, $user, $organization, false);
    }

    private function acceptByTokenUsingIdentityPolicy(
        string $token,
        User $user,
        Organization $organization,
        bool $allowExistingOrganizationEmailMismatch,
    ): ProjectParticipantInvitation
    {
        $invitation = ProjectParticipantInvitation::query()
            ->with([
                'invitedOrganization:id,name,tax_number,email,phone',
                'acceptedBy:id,name',
                'invitedBy:id,name',
                'cancelledBy:id,name',
            ])
            ->where('token', $token)
            ->first();

        if (! $invitation instanceof ProjectParticipantInvitation) {
            throw new BusinessLogicException(trans_message('customer.auth.invitation_not_found'), 404);
        }

        $this->expirePendingInvitations(invitation: $invitation);
        $invitation->refresh();

        if ($invitation->isAccepted()) {
            if ((int) $invitation->accepted_organization_id_snapshot === (int) $organization->id) {
                return $this->freshInvitation($invitation);
            }

            throw new BusinessLogicException(trans_message('customer.auth.invitation_accepted_elsewhere'), 409);
        }

        if ($invitation->isCancelled()) {
            throw new BusinessLogicException(trans_message('customer.auth.invitation_cancelled'), 410);
        }

        if ($invitation->isExpired()) {
            throw new BusinessLogicException(trans_message('customer.auth.invitation_expired'), 410);
        }

        if (
            $invitation->invited_organization_id !== null
            && (int) $invitation->invited_organization_id !== (int) $organization->id
        ) {
            throw new BusinessLogicException(trans_message('customer.auth.invitation_wrong_organization'), 403);
        }

        if ((! $allowExistingOrganizationEmailMismatch || $invitation->invited_organization_id === null)
            && $invitation->email !== null
            && strcasecmp($invitation->email, $user->email) !== 0) {
            throw new BusinessLogicException(trans_message('customer.auth.invitation_wrong_email'), 403);
        }

        if ($invitation->inn !== null
            && ($invitation->invited_organization_id === null || $organization->tax_number !== null)
            && $invitation->inn !== $organization->tax_number) {
            throw new BusinessLogicException(trans_message('customer.auth.invitation_wrong_inn'), 422);
        }

        return $this->acceptInvitation($invitation, $organization, $user, $allowExistingOrganizationEmailMismatch);
    }

    public function previewByToken(string $token): ProjectParticipantInvitation
    {
        return ProjectParticipantInvitation::query()
            ->with(['project:id,name', 'invitedOrganization:id,name'])
            ->where('token', $token)
            ->firstOrFail();
    }

    public function acceptByTokenAsOrganizationOwner(
        string $token,
        User $user,
        Organization $organization
    ): ProjectParticipantInvitation {
        $membership = $organization->users()
            ->whereKey($user->id)
            ->wherePivot('is_active', true)
            ->wherePivot('is_owner', true)
            ->exists();
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organization->id);
        $hasOwnerRole = \App\Domain\Authorization\Models\UserRoleAssignment::query()
            ->where('user_id', $user->id)
            ->where('context_id', $context->id)
            ->where('role_slug', 'organization_owner')
            ->where('role_type', 'system')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();

        if (! $membership || ! $user->is_active || ! $hasOwnerRole) {
            throw new BusinessLogicException(trans_message('project_invitations.errors.owner_required'), 403);
        }

        return $this->acceptByTokenUsingIdentityPolicy($token, $user, $organization, true);
    }

    public function declineByToken(string $token): ProjectParticipantInvitation
    {
        return DB::transaction(function () use ($token): ProjectParticipantInvitation {
            $invitation = ProjectParticipantInvitation::query()
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof ProjectParticipantInvitation) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_not_found'), 404);
            }

            if ($invitation->isAccepted()) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_unavailable'), 409);
            }

            if ($invitation->isCancelled()) {
                return $this->freshInvitation($invitation);
            }

            if ($invitation->isExpired()) {
                if ($invitation->status === ProjectParticipantInvitation::STATUS_PENDING) {
                    $invitation->update([
                        'status' => ProjectParticipantInvitation::STATUS_EXPIRED,
                        'status_reason' => 'expired',
                    ]);
                }

                throw new BusinessLogicException(trans_message('customer.auth.invitation_unavailable'), 410);
            }

            $updated = ProjectParticipantInvitation::query()
                ->whereKey($invitation->id)
                ->where('status', ProjectParticipantInvitation::STATUS_PENDING)
                ->update([
                    'status' => ProjectParticipantInvitation::STATUS_DECLINED,
                    'status_reason' => 'declined',
                    'cancelled_at' => now(),
                    'cancelled_by_user_id' => null,
                ]);

            if ($updated !== 1) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_unavailable'), 409);
            }

            return $this->freshInvitation($invitation);
        });
    }

    public function acceptMatchingForOrganization(User $user, Organization $organization): array
    {
        $invitations = ProjectParticipantInvitation::query()
            ->where('status', ProjectParticipantInvitation::STATUS_PENDING)
            ->where(function ($query) use ($user, $organization): void {
                $query->where('invited_organization_id', $organization->id)
                    ->orWhere(function ($newOrganization) use ($user, $organization): void {
                        $newOrganization->whereNull('invited_organization_id')
                            ->where(function ($identity) use ($user, $organization): void {
                                $identity->where('email', $user->email);

                                if ($organization->tax_number !== null) {
                                    $identity->orWhere('inn', $organization->tax_number);
                                }
                            });
                    });
            })
            ->get();

        $stats = [
            'accepted' => 0,
            'skipped' => 0,
            'conflicted' => 0,
        ];

        foreach ($invitations as $invitation) {
            if ($invitation->hasExpired()) {
                $this->markInvitationExpired($invitation);
                $stats['skipped']++;

                continue;
            }

            try {
                $this->acceptInvitation($invitation, $organization, $user);
                $stats['accepted']++;
            } catch (BusinessLogicException $exception) {
                $stats['conflicted']++;

                Log::warning('project.participant_invitation.auto_accept.skipped', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    private function acceptInvitation(
        ProjectParticipantInvitation $invitation,
        Organization $organization,
        User $user,
        bool $allowExistingOrganizationEmailMismatch = false,
    ): ProjectParticipantInvitation {
        return DB::transaction(function () use ($invitation, $organization, $user, $allowExistingOrganizationEmailMismatch): ProjectParticipantInvitation {
            $invitation = ProjectParticipantInvitation::query()
                ->whereKey($invitation->id)
                ->lockForUpdate()
                ->first();

            if (! $invitation instanceof ProjectParticipantInvitation) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_not_found'), 404);
            }

            if ($invitation->isAccepted()) {
                if ((int) $invitation->accepted_organization_id_snapshot === (int) $organization->id) {
                    return $this->freshInvitation($invitation);
                }

                throw new BusinessLogicException(trans_message('customer.auth.invitation_accepted_elsewhere'), 409);
            }

            if ($invitation->isCancelled()) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_cancelled'), 410);
            }

            if ($invitation->isExpired()) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_expired'), 410);
            }

            $project = $invitation->project()->first();

            if (! $project instanceof Project) {
                throw new BusinessLogicException('Проект приглашения не найден.', 404);
            }

            if (
                $invitation->invited_organization_id !== null
                && (int) $invitation->invited_organization_id !== (int) $organization->id
            ) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_wrong_organization'), 403);
            }

            if ((! $allowExistingOrganizationEmailMismatch || $invitation->invited_organization_id === null)
                && $invitation->email !== null
                && strcasecmp($invitation->email, $user->email) !== 0) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_wrong_email'), 403);
            }

            if ($invitation->inn !== null
                && ($invitation->invited_organization_id === null || $organization->tax_number !== null)
                && $invitation->inn !== $organization->tax_number) {
                throw new BusinessLogicException(trans_message('customer.auth.invitation_wrong_inn'), 422);
            }

            $participant = $project->organizations()
                ->where('organizations.id', $organization->id)
                ->first();

            if ($participant instanceof Organization) {
                $currentRoleValue = $participant->pivot->role_new ?? $participant->pivot->role;
                $currentRole = ProjectOrganizationRole::from($currentRoleValue);

                if ($currentRole !== $invitation->roleEnum()) {
                    $this->projectParticipantService->updateRole(
                        $project,
                        $organization->id,
                        $invitation->roleEnum(),
                        $user
                    );
                }

                if (! (bool) $participant->pivot->is_active) {
                    $this->projectParticipantService->setActiveState($project, $organization->id, true);
                }
            } else {
                $this->projectParticipantService->attach(
                    $project,
                    $organization->id,
                    $invitation->roleEnum(),
                    $user
                );
            }

            $invitation->update([
                'invited_organization_id' => $organization->id,
                'accepted_organization_id_snapshot' => $organization->id,
                'accepted_by_user_id' => $user->id,
                'accepted_at' => now(),
                'status' => ProjectParticipantInvitation::STATUS_ACCEPTED,
                'status_reason' => null,
                'cancelled_at' => null,
                'cancelled_by_user_id' => null,
            ]);

            return $this->freshInvitation($invitation);
        });
    }

    private function expirePendingInvitations(
        ?Project $project = null,
        ?ProjectParticipantInvitation $invitation = null
    ): void {
        $query = ProjectParticipantInvitation::query()
            ->where('status', ProjectParticipantInvitation::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());

        if ($project instanceof Project) {
            $query->where('project_id', $project->id);
        }

        if ($invitation instanceof ProjectParticipantInvitation) {
            $query->whereKey($invitation->id);
        }

        $query->update([
            'status' => ProjectParticipantInvitation::STATUS_EXPIRED,
            'status_reason' => 'expired',
        ]);
    }

    private function markInvitationExpired(ProjectParticipantInvitation $invitation): void
    {
        if (! $invitation->isExpired()) {
            return;
        }

        if ($invitation->status !== ProjectParticipantInvitation::STATUS_EXPIRED) {
            $invitation->update([
                'status' => ProjectParticipantInvitation::STATUS_EXPIRED,
                'status_reason' => 'expired',
            ]);
        }
    }

    private function assertInvitationBelongsToProject(Project $project, ProjectParticipantInvitation $invitation): void
    {
        if ((int) $invitation->project_id !== (int) $project->id) {
            throw new BusinessLogicException('Приглашение не относится к указанному проекту.', 404);
        }
    }

    private function freshInvitation(ProjectParticipantInvitation $invitation): ProjectParticipantInvitation
    {
        return $invitation->fresh([
            'project:id,name',
            'invitedOrganization:id,name,tax_number,email,phone',
            'acceptedBy:id,name',
            'invitedBy:id,name',
            'cancelledBy:id,name',
        ]);
    }

    private function sendInvitationEmail(ProjectParticipantInvitation $invitation): void
    {
        $invitation->loadMissing([
            'project:id,name',
            'organization:id,name',
            'invitedBy:id,name',
            'invitedOrganization:id,name,email',
        ]);

        $email = $this->resolveInvitationRecipientEmail($invitation);

        if ($email === null) {
            Log::warning('project.participant_invitation.email_skipped', [
                'invitation_id' => $invitation->id,
                'project_id' => $invitation->project_id,
            ]);

            return;
        }

        $acceptUrl = rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/project-invitations/'
            .urlencode((string) $invitation->token);

        Mail::to($email)->send(new ProjectParticipantInvitationMail($invitation, $acceptUrl));
    }

    private function resolveInvitationRecipientEmail(ProjectParticipantInvitation $invitation): ?string
    {
        foreach ([$invitation->email, $invitation->invitedOrganization?->email] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }
}
