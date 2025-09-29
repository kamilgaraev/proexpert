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
use App\Services\Logging\LoggingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Carbon\Carbon;

class UserInvitationService
{
    protected SubscriptionLimitsService $subscriptionLimitsService;
    protected LoggingService $logging;

    public function __construct(SubscriptionLimitsService $subscriptionLimitsService, LoggingService $logging)
    {
        $this->subscriptionLimitsService = $subscriptionLimitsService;
        $this->logging = $logging;
    }

    public function createInvitation(array $data, int $organizationId, User $invitedBy): UserInvitation
    {
        $startTime = microtime(true);
        
        $this->logging->business('user_invitation.creation.started', [
            'organization_id' => $organizationId,
            'invited_by_user_id' => $invitedBy->id,
            'invited_email' => $data['email'] ?? 'unknown',
            'invited_name' => $data['name'] ?? 'unknown',
            'roles_count' => count($data['role_slugs'] ?? [])
        ]);

        try {
            $this->validateInvitationData($data, $organizationId);
            
            // SECURITY: Проверка лимитов подписки
            if (!$this->subscriptionLimitsService->canCreateUser($invitedBy)) {
                $this->logging->security('user_invitation.limit_exceeded', [
                    'organization_id' => $organizationId,
                    'invited_by_user_id' => $invitedBy->id,
                    'invited_email' => $data['email'],
                    'subscription_limit_reached' => true
                ], 'warning');
                
                throw new BusinessLogicException('Достигнут лимит пользователей по вашему тарифному плану');
            }

            // SECURITY: Проверка существующих пользователей
            $existingUser = User::where('email', $data['email'])->first();
            if ($existingUser && $existingUser->belongsToOrganization($organizationId)) {
                $this->logging->security('user_invitation.duplicate_user', [
                    'organization_id' => $organizationId,
                    'invited_by_user_id' => $invitedBy->id,
                    'invited_email' => $data['email'],
                    'existing_user_id' => $existingUser->id,
                    'already_in_organization' => true
                ], 'warning');
                
                throw new BusinessLogicException('Пользователь с таким email уже состоит в организации');
            }

            // BUSINESS: Проверка существующих приглашений
            $existingInvitation = UserInvitation::where('email', $data['email'])
                ->where('organization_id', $organizationId)
                ->where('status', InvitationStatus::PENDING)
                ->first();

            if ($existingInvitation && !$existingInvitation->isExpired()) {
                $this->logging->business('user_invitation.duplicate_invitation', [
                    'organization_id' => $organizationId,
                    'invited_email' => $data['email'],
                    'existing_invitation_id' => $existingInvitation->id,
                    'existing_invitation_created' => $existingInvitation->created_at
                ], 'warning');
                
                throw new BusinessLogicException('Активное приглашение для этого email уже существует');
            }

            if ($existingInvitation) {
                $existingInvitation->markAsExpired();
                
                $this->logging->technical('user_invitation.expired_previous', [
                    'organization_id' => $organizationId,
                    'invitation_id' => $existingInvitation->id,
                    'invited_email' => $data['email']
                ]);
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
                
                $duration = (microtime(true) - $startTime) * 1000;
                
                $this->logging->business('user_invitation.created', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $organizationId,
                    'invited_by_user_id' => $invitedBy->id,
                    'invited_email' => $invitation->email,
                    'invited_name' => $invitation->name,
                    'roles' => $invitation->role_slugs,
                    'duration_ms' => $duration
                ]);
                
                $this->logging->audit('user_invitation.created', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $organizationId,
                    'invited_by' => $invitedBy->id,
                    'invited_email' => $invitation->email,
                    'roles' => $invitation->role_slugs,
                    'performed_by' => $invitedBy->id
                ]);
                
                return $invitation;
                
            } catch (\Exception $e) {
                DB::rollBack();
                
                $duration = (microtime(true) - $startTime) * 1000;
                
                $this->logging->technical('user_invitation.creation.failed', [
                    'organization_id' => $organizationId,
                    'invited_by_user_id' => $invitedBy->id,
                    'invited_email' => $data['email'],
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration
                ], 'error');
                
                throw new BusinessLogicException('Ошибка при создании приглашения: ' . $e->getMessage());
            }
        } catch (BusinessLogicException $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('user_invitation.creation.rejected', [
                'organization_id' => $organizationId,
                'invited_by_user_id' => $invitedBy->id,
                'invited_email' => $data['email'] ?? 'unknown',
                'reason' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'warning');
            
            throw $e;
        }
    }

    public function acceptInvitation(string $token, array $userData = []): User
    {
        $startTime = microtime(true);
        
        $this->logging->business('user_invitation.acceptance.started', [
            'token_provided' => !empty($token),
            'user_data_provided' => !empty($userData)
        ]);

        try {
            $invitation = UserInvitation::where('token', $token)->first();

            if (!$invitation) {
                $this->logging->security('user_invitation.acceptance.invalid_token', [
                    'token' => substr($token, 0, 8) . '...',
                    'token_not_found' => true
                ], 'warning');
                
                throw new BusinessLogicException('Приглашение не найдено');
            }

            $this->logging->technical('user_invitation.acceptance.token_found', [
                'invitation_id' => $invitation->id,
                'organization_id' => $invitation->organization_id,
                'invitation_email' => $invitation->email,
                'invitation_status' => $invitation->status->value,
                'invitation_created' => $invitation->created_at,
                'invitation_expires' => $invitation->expires_at
            ]);

            if (!$invitation->canBeAccepted()) {
                $this->logging->security('user_invitation.acceptance.invalid_invitation', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                    'invitation_email' => $invitation->email,
                    'invitation_status' => $invitation->status->value,
                    'is_expired' => $invitation->isExpired(),
                    'reason' => 'invitation_cannot_be_accepted'
                ], 'warning');
                
                throw new BusinessLogicException('Приглашение недействительно или истекло');
            }

            DB::beginTransaction();
            try {
                $existingUser = User::where('email', $invitation->email)->first();
                
                if ($existingUser) {
                    $this->logging->technical('user_invitation.acceptance.existing_user', [
                        'invitation_id' => $invitation->id,
                        'existing_user_id' => $existingUser->id,
                        'user_email' => $existingUser->email
                    ]);
                    
                    $user = $this->addExistingUserToOrganization($existingUser, $invitation);
                } else {
                    $this->logging->technical('user_invitation.acceptance.new_user', [
                        'invitation_id' => $invitation->id,
                        'invitation_email' => $invitation->email
                    ]);
                    
                    $user = $this->createNewUserFromInvitation($invitation, $userData);
                }

                $this->assignRolesToUser($user, $invitation);
                $invitation->markAsAccepted($user);

                DB::commit();
                
                $duration = (microtime(true) - $startTime) * 1000;
                
                $this->logging->business('user_invitation.accepted', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'roles_assigned' => $invitation->role_slugs,
                    'was_existing_user' => $existingUser !== null,
                    'duration_ms' => $duration
                ]);
                
                $this->logging->audit('user_invitation.accepted', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'invited_by' => $invitation->invited_by_user_id,
                    'roles_assigned' => $invitation->role_slugs,
                    'performed_by' => $user->id
                ]);
                
                $this->logging->security('user_invitation.new_user_joined', [
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'roles' => $invitation->role_slugs,
                    'invited_by' => $invitation->invited_by_user_id
                ]);
                
                return $user;
                
            } catch (\Exception $e) {
                DB::rollBack();
                
                $duration = (microtime(true) - $startTime) * 1000;
                
                $this->logging->technical('user_invitation.acceptance.failed', [
                    'invitation_id' => $invitation->id,
                    'organization_id' => $invitation->organization_id,
                    'invitation_email' => $invitation->email,
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration
                ], 'error');
                
                throw new BusinessLogicException('Ошибка при принятии приглашения: ' . $e->getMessage());
            }
        } catch (BusinessLogicException $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('user_invitation.acceptance.rejected', [
                'reason' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'warning');
            
            throw $e;
        }
    }

    public function cancelInvitation(int $invitationId, int $organizationId): bool
    {
        $this->logging->business('user_invitation.cancellation.started', [
            'invitation_id' => $invitationId,
            'organization_id' => $organizationId
        ]);

        try {
            $invitation = UserInvitation::where('id', $invitationId)
                ->where('organization_id', $organizationId)
                ->first();

            if (!$invitation) {
                $this->logging->business('user_invitation.cancellation.not_found', [
                    'invitation_id' => $invitationId,
                    'organization_id' => $organizationId
                ], 'warning');
                
                throw new BusinessLogicException('Приглашение не найдено');
            }

            if ($invitation->status !== InvitationStatus::PENDING) {
                $this->logging->business('user_invitation.cancellation.invalid_status', [
                    'invitation_id' => $invitationId,
                    'organization_id' => $organizationId,
                    'current_status' => $invitation->status->value,
                    'required_status' => InvitationStatus::PENDING->value
                ], 'warning');
                
                throw new BusinessLogicException('Можно отменить только ожидающие приглашения');
            }

            $invitation->markAsCancelled();
            
            $this->logging->business('user_invitation.cancelled', [
                'invitation_id' => $invitation->id,
                'organization_id' => $organizationId,
                'invitation_email' => $invitation->email,
                'invited_by' => $invitation->invited_by_user_id
            ]);
            
            $this->logging->audit('user_invitation.cancelled', [
                'invitation_id' => $invitation->id,
                'organization_id' => $organizationId,
                'invitation_email' => $invitation->email,
                'invited_by' => $invitation->invited_by_user_id,
                'performed_by' => auth()->id() ?? 'system'
            ]);
            
            return true;
            
        } catch (BusinessLogicException $e) {
            $this->logging->business('user_invitation.cancellation.failed', [
                'invitation_id' => $invitationId,
                'organization_id' => $organizationId,
                'reason' => $e->getMessage()
            ], 'warning');
            
            throw $e;
        }
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
        $startTime = microtime(true);
        
        $this->logging->technical('user_invitation.cleanup.started');

        try {
            $expiredCount = UserInvitation::where('status', InvitationStatus::PENDING)
                ->where('expires_at', '<', now())
                ->count();

            if ($expiredCount > 0) {
                UserInvitation::where('status', InvitationStatus::PENDING)
                    ->where('expires_at', '<', now())
                    ->update(['status' => InvitationStatus::EXPIRED]);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->business('user_invitation.cleanup.completed', [
                'expired_count' => $expiredCount,
                'duration_ms' => $duration
            ]);
            
            if ($expiredCount > 10) {
                $this->logging->business('user_invitation.cleanup.high_expired_count', [
                    'expired_count' => $expiredCount,
                    'potential_issue' => 'high_invitation_expiry_rate'
                ], 'warning');
            }

            return $expiredCount;
            
        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('user_invitation.cleanup.failed', [
                'error' => $e->getMessage(),
                'duration_ms' => $duration
            ], 'error');
            
            throw $e;
        }
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
            // 'user_type' => 'organization_user', // Удалена в новой системе авторизации
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