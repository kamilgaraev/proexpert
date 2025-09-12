<?php

namespace App\Services;

use App\Models\UserInvitation;
use App\Models\User;
use App\Models\Organization;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\UserInvitation\InvitationStatus;
use App\Exceptions\BusinessLogicException;
use App\Services\Billing\SubscriptionLimitsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserInvitationService
{
    protected SubscriptionLimitsService $subscriptionLimitsService;

    public function __construct(SubscriptionLimitsService $subscriptionLimitsService)
    {
        $this->subscriptionLimitsService = $subscriptionLimitsService;
    }

    public function createInvitation(array $data, int $organizationId, User $invitedBy): UserInvitation
    {
        $this->validateInvitationData($data, $organizationId);
        
        if (!$this->subscriptionLimitsService->canCreateUser($invitedBy)) {
            throw new BusinessLogicException('Достигнут лимит пользователей по вашему тарифному плану');
        }

        $existingUser = User::where('email', $data['email'])->first();
        if ($existingUser && $existingUser->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException('Пользователь с таким email уже состоит в организации');
        }

        $existingInvitation = UserInvitation::where('email', $data['email'])
            ->where('organization_id', $organizationId)
            ->where('status', InvitationStatus::PENDING)
            ->first();

        if ($existingInvitation && !$existingInvitation->isExpired()) {
            throw new BusinessLogicException('Активное приглашение для этого email уже существует');
        }

        if ($existingInvitation) {
            $existingInvitation->markAsExpired();
        }

        DB::beginTransaction();
        try {
            $invitation = UserInvitation::create([
                'organization_id' => $organizationId,
                'invited_by_user_id' => $invitedBy->id,
                'email' => $data['email'],
                'name' => $data['name'],
                'role_slugs' => $data['role_slugs'],
                'metadata' => $data['metadata'] ?? null,
            ]);

            $this->sendInvitationEmail($invitation);

            DB::commit();
            return $invitation;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessLogicException('Ошибка при создании приглашения: ' . $e->getMessage());
        }
    }

    public function acceptInvitation(string $token, array $userData = []): User
    {
        $invitation = UserInvitation::where('token', $token)->first();

        if (!$invitation) {
            throw new BusinessLogicException('Приглашение не найдено');
        }

        if (!$invitation->canBeAccepted()) {
            throw new BusinessLogicException('Приглашение недействительно или истекло');
        }

        DB::beginTransaction();
        try {
            $existingUser = User::where('email', $invitation->email)->first();
            
            if ($existingUser) {
                $user = $this->addExistingUserToOrganization($existingUser, $invitation);
            } else {
                $user = $this->createNewUserFromInvitation($invitation, $userData);
            }

            $this->assignRolesToUser($user, $invitation);
            $invitation->markAsAccepted($user);

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new BusinessLogicException('Ошибка при принятии приглашения: ' . $e->getMessage());
        }
    }

    public function cancelInvitation(int $invitationId, int $organizationId): bool
    {
        $invitation = UserInvitation::where('id', $invitationId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$invitation) {
            throw new BusinessLogicException('Приглашение не найдено');
        }

        if ($invitation->status !== InvitationStatus::PENDING) {
            throw new BusinessLogicException('Можно отменить только ожидающие приглашения');
        }

        $invitation->markAsCancelled();
        return true;
    }

    public function resendInvitation(int $invitationId, int $organizationId): UserInvitation
    {
        $invitation = UserInvitation::where('id', $invitationId)
            ->where('organization_id', $organizationId)
            ->first();

        if (!$invitation) {
            throw new BusinessLogicException('Приглашение не найдено');
        }

        if ($invitation->status !== InvitationStatus::PENDING) {
            throw new BusinessLogicException('Можно переслать только ожидающие приглашения');
        }

        $invitation->regenerateToken();
        $this->sendInvitationEmail($invitation);

        return $invitation->fresh();
    }

    public function getInvitationsForOrganization(int $organizationId, array $filters = []): Collection
    {
        $query = UserInvitation::where('organization_id', $organizationId)
            ->with(['invitedBy', 'acceptedBy']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['email'])) {
            $query->where('email', 'like', '%' . $filters['email'] . '%');
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getInvitationByToken(string $token): ?UserInvitation
    {
        return UserInvitation::where('token', $token)
            ->with(['organization', 'invitedBy'])
            ->first();
    }

    public function cleanupExpiredInvitations(): int
    {
        $expiredCount = UserInvitation::where('status', InvitationStatus::PENDING)
            ->where('expires_at', '<', now())
            ->count();

        UserInvitation::where('status', InvitationStatus::PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => InvitationStatus::EXPIRED]);

        return $expiredCount;
    }

    private function validateInvitationData(array $data, int $organizationId): void
    {
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new BusinessLogicException('Некорректный email адрес');
        }

        if (empty($data['name'])) {
            throw new BusinessLogicException('Имя пользователя обязательно');
        }

        if (empty($data['role_slugs']) || !is_array($data['role_slugs'])) {
            throw new BusinessLogicException('Необходимо указать роли для пользователя');
        }

        $validRoles = ['organization_admin', 'foreman', 'web_admin', 'accountant'];
        $invalidRoles = array_diff($data['role_slugs'], $validRoles);
        
        if (!empty($invalidRoles)) {
            throw new BusinessLogicException('Недопустимые роли: ' . implode(', ', $invalidRoles));
        }
    }

    private function sendInvitationEmail(UserInvitation $invitation): void
    {
        // TODO: Реализовать отправку email
        // Mail::to($invitation->email)->send(new InvitationMail($invitation));
    }

    private function addExistingUserToOrganization(User $user, UserInvitation $invitation): User
    {
        if (!$user->belongsToOrganization($invitation->organization_id)) {
            $user->organizations()->attach($invitation->organization_id);
        }

        return $user;
    }

    private function createNewUserFromInvitation(UserInvitation $invitation, array $userData): User
    {
        $password = $userData['password'] ?? $this->generateTemporaryPassword();

        $user = User::create([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'user_type' => 'organization_user',
        ]);

        $user->organizations()->attach($invitation->organization_id);

        return $user;
    }

    private function assignRolesToUser(User $user, UserInvitation $invitation): void
    {
        // Получаем или создаем контекст организации
        $context = AuthorizationContext::getOrganizationContext($invitation->organization_id);
        
        foreach ($invitation->role_slugs as $roleSlug) {
            // Проверяем, не назначена ли уже роль
            $existing = UserRoleAssignment::where([
                'user_id' => $user->id,
                'role_slug' => $roleSlug,
                'context_id' => $context->id,
                'is_active' => true
            ])->exists();
            
            if (!$existing) {
                UserRoleAssignment::create([
                    'user_id' => $user->id,
                    'role_slug' => $roleSlug,
                    'role_type' => 'system', // Системная роль из JSON
                    'context_id' => $context->id,
                    'assigned_by' => auth()->id(),
                    'is_active' => true
                ]);
            }
        }
    }

    private function generateTemporaryPassword(): string
    {
        return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 12);
    }

    public function getInvitationStats(int $organizationId): array
    {
        $total = UserInvitation::where('organization_id', $organizationId)->count();
        $pending = UserInvitation::where('organization_id', $organizationId)
            ->where('status', InvitationStatus::PENDING)
            ->count();
        $accepted = UserInvitation::where('organization_id', $organizationId)
            ->where('status', InvitationStatus::ACCEPTED)
            ->count();
        $expired = UserInvitation::where('organization_id', $organizationId)
            ->where('status', InvitationStatus::EXPIRED)
            ->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'accepted' => $accepted,
            'expired' => $expired,
            'acceptance_rate' => $total > 0 ? round(($accepted / $total) * 100, 1) : 0,
        ];
    }
} 