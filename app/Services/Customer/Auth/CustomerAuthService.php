<?php

declare(strict_types=1);

namespace App\Services\Customer\Auth;

use App\DTOs\Auth\LoginDTO;
use App\DTOs\Auth\RegisterDTO;
use App\Models\Organization;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use App\Notifications\CustomerResetPasswordNotification;
use App\Repositories\Interfaces\OrganizationRepositoryInterface;
use App\Repositories\Interfaces\UserRepositoryInterface;
use App\Services\Auth\JwtAuthService;
use App\Services\Customer\CustomerPortalService;
use App\Services\Project\ProjectParticipantInvitationService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;

use function trans_message;

class CustomerAuthService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly CustomerPortalService $customerPortalService,
        private readonly ProjectParticipantInvitationService $invitationService,
        private readonly JwtAuthService $jwtAuthService,
    ) {
    }

    public function login(LoginDTO $loginDTO, string $guard): array
    {
        Auth::shouldUse($guard);

        if (!Auth::validate($loginDTO->toArray())) {
            return [
                'success' => false,
                'message' => trans_message('customer.auth.invalid_credentials'),
                'status_code' => 401,
            ];
        }

        /** @var User|null $user */
        $user = Auth::getLastAttempted();

        if (!$user instanceof User) {
            return [
                'success' => false,
                'message' => trans_message('customer.auth.invalid_credentials'),
                'status_code' => 401,
            ];
        }

        if (!$user->hasVerifiedEmail()) {
            return [
                'success' => false,
                'message' => trans_message('customer.auth.email_verification_required'),
                'status_code' => 403,
                'email_verified' => false,
                'status' => 'verification_required',
                'email' => $user->email,
            ];
        }

        $organization = $this->resolveActiveOrganization($user);

        if (!$organization instanceof Organization) {
            return [
                'success' => false,
                'message' => trans_message('customer.auth.organization_access_missing'),
                'status_code' => 403,
            ];
        }

        $this->syncCurrentOrganization($user, $organization);

        $token = JWTAuth::claims(['organization_id' => $organization->id])->fromUser($user);
        $profile = $this->customerPortalService->getProfile($user->fresh(), $organization->id);
        $interfaces = $profile['user']['interfaces'] ?? [];

        return [
            'success' => true,
            'status_code' => 200,
            'token' => $token,
            'user' => $profile['user'],
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'email_verified' => true,
            'available_interfaces' => $interfaces,
        ];
    }

    public function authenticate(LoginDTO $loginDTO, string $guard): array
    {
        return $this->login($loginDTO, $guard);
    }

    public function register(RegisterDTO $registerDTO, ?string $verificationFrontendUrl = null): array
    {
        $stats = ['accepted' => 0, 'skipped' => 0, 'conflicted' => 0];

        /** @var array{user: User, organization: Organization} $result */
        $result = DB::transaction(function () use ($registerDTO): array {
            /** @var User $user */
            $user = $this->userRepository->create($registerDTO->getUserData());
            /** @var Organization $organization */
            $organization = $this->organizationRepository->create($registerDTO->getOrganizationData());

            $this->userRepository->attachToOrganization($user->id, $organization->id, true, true);

            try {
                $this->userRepository->assignRoleToUser($user->id, 'customer_owner', $organization->id);
            } catch (\Throwable $exception) {
                Log::warning('customer.auth.register.role_assignment_failed', [
                    'user_id' => $user->id,
                    'organization_id' => $organization->id,
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->syncCurrentOrganization($user, $organization);

            return [
                'user' => $user->fresh(),
                'organization' => $organization->fresh(),
            ];
        });

        try {
            $stats = $this->invitationService->acceptMatchingForOrganization($result['user'], $result['organization']);
        } catch (\Throwable $exception) {
            Log::warning('customer.auth.register.invitation_auto_accept_failed', [
                'user_id' => $result['user']->id,
                'organization_id' => $result['organization']->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $this->sendVerificationNotification($result['user'], $verificationFrontendUrl);

        return [
            'success' => true,
            'status_code' => 201,
            'status' => 'verification_required',
            'email_verified' => false,
            'user' => [
                'id' => $result['user']->id,
                'name' => $result['user']->name,
                'email' => $result['user']->email,
            ],
            'organization' => [
                'id' => $result['organization']->id,
                'name' => $result['organization']->name,
            ],
            'email' => $result['user']->email,
            'can_enter_portal' => false,
            'processed_invitations' => $stats,
        ];
    }

    public function logout(string $guard): array
    {
        return $this->jwtAuthService->logout($guard);
    }

    public function refresh(string $guard): array
    {
        return $this->jwtAuthService->refresh($guard);
    }

    public function sendResetLink(string $email): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user instanceof User) {
            return [
                'success' => true,
                'status_code' => 200,
            ];
        }

        $token = Password::broker('users')->createToken($user);
        $url = sprintf(
            '%s/reset-password?token=%s&email=%s',
            rtrim((string) config('app.customer_frontend_url'), '/'),
            urlencode($token),
            urlencode($user->email)
        );

        $user->notify(new CustomerResetPasswordNotification($url));

        return [
            'success' => true,
            'status_code' => 200,
        ];
    }

    public function resetPassword(array $payload): array
    {
        $status = Password::broker('users')->reset(
            [
                'email' => $payload['email'],
                'password' => $payload['password'],
                'password_confirmation' => $payload['password_confirmation'],
                'token' => $payload['token'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => trans_message('customer.auth.reset_password_invalid'),
            ];
        }

        return [
            'success' => true,
            'status_code' => 200,
        ];
    }

    public function resolveInvitation(string $token): array
    {
        $invitation = $this->findInvitation($token);

        if (!$invitation instanceof ProjectParticipantInvitation) {
            return [
                'success' => false,
                'status_code' => 404,
                'message' => trans_message('customer.auth.invitation_not_found'),
            ];
        }

        $status = $this->resolveInvitationStatus($invitation);

        return [
            'success' => true,
            'status_code' => 200,
            'invitation' => [
                'token' => $invitation->token,
                'status' => $status,
                'role' => $invitation->role,
                'email' => $invitation->email,
                'organization' => [
                    'id' => $invitation->invited_organization_id,
                    'name' => $invitation->invitedOrganization?->name ?? $invitation->organization_name,
                    'inn' => $invitation->invitedOrganization?->tax_number ?? $invitation->inn,
                ],
                'project' => [
                    'id' => $invitation->project?->id,
                    'name' => $invitation->project?->name,
                ],
                'next_action' => $this->resolveInvitationNextAction($invitation, $status),
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        ];
    }

    public function loginByInvitation(string $token, LoginDTO $loginDTO, string $guard): array
    {
        $loginResult = $this->login($loginDTO, $guard);

        if (!$loginResult['success']) {
            return $loginResult;
        }

        /** @var User|null $user */
        $user = $this->userRepository->findByEmail($loginDTO->getEmail());

        if (!$user instanceof User) {
            return [
                'success' => false,
                'status_code' => 401,
                'message' => trans_message('customer.auth.invalid_credentials'),
            ];
        }

        $organization = $this->resolveActiveOrganization($user);

        if (!$organization instanceof Organization) {
            return [
                'success' => false,
                'status_code' => 403,
                'message' => trans_message('customer.auth.organization_access_missing'),
            ];
        }

        $invitation = $this->invitationService->acceptByToken($token, $user, $organization);

        $loginResult['invitation'] = [
            'id' => $invitation->id,
            'status' => $invitation->status,
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
        ];

        return $loginResult;
    }

    public function registerByInvitation(
        string $token,
        RegisterDTO $registerDTO,
        ?string $verificationFrontendUrl = null
    ): array {
        $invitation = $this->findInvitation($token);

        if (!$invitation instanceof ProjectParticipantInvitation) {
            return [
                'success' => false,
                'status_code' => 404,
                'message' => trans_message('customer.auth.invitation_not_found'),
            ];
        }

        if ($this->resolveInvitationStatus($invitation) !== ProjectParticipantInvitation::STATUS_PENDING) {
            return [
                'success' => false,
                'status_code' => 409,
                'message' => trans_message('customer.auth.invitation_unavailable'),
            ];
        }

        if ($invitation->invited_organization_id !== null) {
            return [
                'success' => false,
                'status_code' => 422,
                'message' => trans_message('customer.auth.invitation_existing_organization_login_required'),
            ];
        }

        $result = $this->register($registerDTO, $verificationFrontendUrl);

        if (!$result['success']) {
            return $result;
        }

        /** @var User|null $user */
        $user = $this->userRepository->findByEmail($registerDTO->getEmail());
        $organization = $user instanceof User ? $this->resolveActiveOrganization($user) : null;

        if ($user instanceof User && $organization instanceof Organization) {
            $acceptedInvitation = $this->invitationService->acceptByToken($token, $user, $organization);

            $result['invitation'] = [
                'id' => $acceptedInvitation->id,
                'status' => $acceptedInvitation->status,
                'accepted_at' => $acceptedInvitation->accepted_at?->toIso8601String(),
            ];
        }

        return $result;
    }

    private function findInvitation(string $token): ?ProjectParticipantInvitation
    {
        return ProjectParticipantInvitation::query()
            ->with([
                'project:id,name',
                'invitedOrganization:id,name,tax_number',
            ])
            ->where('token', $token)
            ->first();
    }

    private function resolveInvitationStatus(ProjectParticipantInvitation $invitation): string
    {
        if ($invitation->isAccepted()) {
            return ProjectParticipantInvitation::STATUS_ACCEPTED;
        }

        if ($invitation->isCancelled()) {
            return ProjectParticipantInvitation::STATUS_CANCELLED;
        }

        if ($invitation->isExpired()) {
            return ProjectParticipantInvitation::STATUS_EXPIRED;
        }

        return $invitation->status;
    }

    private function resolveInvitationNextAction(ProjectParticipantInvitation $invitation, string $status): string
    {
        if ($status !== ProjectParticipantInvitation::STATUS_PENDING) {
            return 'unavailable';
        }

        if ($invitation->invited_organization_id !== null) {
            return 'login';
        }

        return 'login_or_register';
    }

    private function sendVerificationNotification(User $user, ?string $verificationFrontendUrl): void
    {
        try {
            if ($verificationFrontendUrl !== null && $verificationFrontendUrl !== '') {
                $user->sendFrontendEmailVerificationNotification($verificationFrontendUrl);
                return;
            }

            $user->sendFrontendEmailVerificationNotification((string) config('app.customer_frontend_url'));
        } catch (\Throwable $exception) {
            Log::warning('customer.auth.verification_notification_failed', [
                'user_id' => $user->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveActiveOrganization(User $user): ?Organization
    {
        $currentOrganization = $user->currentOrganization()
            ->whereHas('users', function ($query) use ($user): void {
                $query
                    ->where('users.id', $user->id)
                    ->where('organization_user.is_active', true);
            })
            ->first();

        if ($currentOrganization instanceof Organization) {
            return $currentOrganization;
        }

        return $user->organizations()
            ->where('organization_user.is_active', true)
            ->orderByDesc('organization_user.is_owner')
            ->select('organizations.*')
            ->first();
    }

    private function syncCurrentOrganization(User $user, Organization $organization): void
    {
        if ((int) $user->current_organization_id === (int) $organization->id) {
            return;
        }

        $user->current_organization_id = $organization->id;
        $user->save();
    }
}
