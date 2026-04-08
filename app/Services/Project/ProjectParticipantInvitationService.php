<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProjectParticipantInvitationService
{
    public function __construct(
        private readonly ProjectService $projectService
    ) {
    }

    public function list(Project $project): Collection
    {
        return ProjectParticipantInvitation::query()
            ->with(['invitedOrganization:id,name,tax_number,email,phone', 'acceptedBy:id,name', 'invitedBy:id,name'])
            ->where('project_id', $project->id)
            ->latest()
            ->get();
    }

    public function create(Project $project, int $organizationId, User $user, array $payload): ProjectParticipantInvitation
    {
        $role = ProjectOrganizationRole::from((string) $payload['role']);

        if ($role !== ProjectOrganizationRole::CUSTOMER) {
            throw new BusinessLogicException('Приглашения участников проекта сейчас поддерживаются только для роли заказчика.', 422);
        }

        $invitedOrganizationId = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;

        if ($invitedOrganizationId !== null) {
            if ($project->organizations()->where('organizations.id', $invitedOrganizationId)->wherePivot('is_active', true)->exists()) {
                throw new BusinessLogicException('Организация уже участвует в проекте.', 409);
            }
        }

        if ($project->organizations()
            ->wherePivot('is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('project_organization.role_new', ProjectOrganizationRole::CUSTOMER->value)
                    ->orWhere(function ($fallbackQuery): void {
                        $fallbackQuery
                            ->whereNull('project_organization.role_new')
                            ->where('project_organization.role', ProjectOrganizationRole::CUSTOMER->value);
                    });
            })
            ->exists()) {
            throw new BusinessLogicException('В проекте уже есть активный заказчик.', 409);
        }

        $email = $payload['email'] ?? null;
        $organizationName = $payload['organization_name'] ?? null;

        if ($invitedOrganizationId === null && ($email === null || $organizationName === null)) {
            throw new BusinessLogicException('Для незарегистрированного заказчика обязательны email и название организации.', 422);
        }

        $existingPending = ProjectParticipantInvitation::query()
            ->where('project_id', $project->id)
            ->where('status', ProjectParticipantInvitation::STATUS_PENDING)
            ->when($invitedOrganizationId !== null, fn ($query) => $query->where('invited_organization_id', $invitedOrganizationId))
            ->when($invitedOrganizationId === null && $email !== null, fn ($query) => $query->where('email', $email))
            ->first();

        if ($existingPending instanceof ProjectParticipantInvitation) {
            throw new BusinessLogicException('Активное приглашение для этого заказчика уже существует.', 409);
        }

        return ProjectParticipantInvitation::create([
            'project_id' => $project->id,
            'organization_id' => $organizationId,
            'invited_by_user_id' => $user->id,
            'invited_organization_id' => $invitedOrganizationId,
            'role' => $role->value,
            'status' => ProjectParticipantInvitation::STATUS_PENDING,
            'organization_name' => $organizationName,
            'inn' => $payload['inn'] ?? null,
            'email' => $email,
            'contact_name' => $payload['contact_name'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'message' => $payload['message'] ?? null,
            'metadata' => $payload['metadata'] ?? null,
        ]);
    }

    public function acceptByToken(string $token, User $user, Organization $organization): ProjectParticipantInvitation
    {
        $invitation = ProjectParticipantInvitation::query()
            ->where('token', $token)
            ->first();

        if (!$invitation instanceof ProjectParticipantInvitation || !$invitation->isPending()) {
            throw new BusinessLogicException('Приглашение не найдено или уже недоступно.', 404);
        }

        if ($invitation->email !== null && strcasecmp($invitation->email, $user->email) !== 0) {
            throw new BusinessLogicException('Это приглашение выписано на другой email.', 403);
        }

        if ($invitation->inn !== null && $organization->tax_number !== null && $invitation->inn !== $organization->tax_number) {
            throw new BusinessLogicException('ИНН организации не совпадает с приглашением.', 422);
        }

        return $this->acceptInvitation($invitation, $organization, $user);
    }

    public function acceptMatchingForOrganization(User $user, Organization $organization): int
    {
        $invitations = ProjectParticipantInvitation::query()
            ->pending()
            ->where(function ($query) use ($user, $organization): void {
                $query->where('email', $user->email);

                if ($organization->tax_number !== null) {
                    $query->orWhere('inn', $organization->tax_number);
                }
            })
            ->get();

        $accepted = 0;

        foreach ($invitations as $invitation) {
            $this->acceptInvitation($invitation, $organization, $user);
            $accepted++;
        }

        return $accepted;
    }

    private function acceptInvitation(
        ProjectParticipantInvitation $invitation,
        Organization $organization,
        User $user
    ): ProjectParticipantInvitation {
        return DB::transaction(function () use ($invitation, $organization, $user): ProjectParticipantInvitation {
            if (!$invitation->project()->exists()) {
                throw new BusinessLogicException('Проект приглашения не найден.', 404);
            }

            $project = $invitation->project()->firstOrFail();

            $this->projectService->attachOrganizationToProjectEntity(
                $project,
                $organization->id,
                ProjectOrganizationRole::from($invitation->role),
                $user
            );

            $invitation->update([
                'invited_organization_id' => $organization->id,
                'accepted_by_user_id' => $user->id,
                'accepted_at' => now(),
                'status' => ProjectParticipantInvitation::STATUS_ACCEPTED,
            ]);

            return $invitation->fresh(['invitedOrganization:id,name,tax_number,email,phone', 'acceptedBy:id,name', 'invitedBy:id,name']);
        });
    }
}
