<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use App\Repositories\UserRepository;
use App\Services\Billing\SubscriptionLimitsService;
use App\Services\User\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function trans_message;

class CustomUserManagementController extends Controller
{
    public function __construct(
        protected CustomRoleService $customRoleService,
        protected AuthorizationService $authService,
        protected RoleScanner $roleScanner,
        protected RolePayloadFormatter $rolePayloadFormatter,
        protected UserRepository $userRepository,
        protected SubscriptionLimitsService $subscriptionLimitsService,
        protected UserService $userService
    ) {
    }

    public function createUserWithCustomRoles(Request $request): JsonResponse
    {
        if ($request->has('email')) {
            $request->merge([
                'email' => Str::lower(trim((string) $request->input('email'))),
            ]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => [
                'bail',
                'required',
                'email',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $email = Str::lower(trim((string) $value));

                    if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                        $fail(trans_message('landing_users.admin_panel_email_exists'));
                    }
                },
            ],
            'password' => 'required|string|min:8|confirmed',
            'custom_role_ids' => 'nullable|array',
            'custom_role_ids.*' => 'integer|exists:organization_custom_roles,id',
            'roles' => 'nullable|array',
            'roles.*' => 'string',
            'send_credentials' => 'sometimes|boolean',
        ]);

        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return $this->organizationContextMissingResponse();
        }

        $organizationId = (int) $organizationId;

        if (in_array('organization_owner', $data['roles'] ?? [], true)) {
            return LandingResponse::error(trans_message('landing_users.owner_generic_assignment_forbidden'), 422);
        }

        try {
            $data['password'] = Hash::make($data['password']);
            $data['current_organization_id'] = $organizationId;

            $user = DB::transaction(function () use ($data, $organizationId): User {
                $user = $this->userRepository->create($data);
                $this->userRepository->attachToOrganization($user->id, $organizationId, false, true);

                $authContext = AuthorizationContext::getOrganizationContext($organizationId);

                if (!empty($data['custom_role_ids'])) {
                    foreach ($data['custom_role_ids'] as $roleId) {
                        $role = OrganizationCustomRole::findOrFail($roleId);
                        $this->customRoleService->assignRoleToUser($role, $user, $authContext);
                    }
                }

                if (!empty($data['roles'])) {
                    foreach ($data['roles'] as $roleSlug) {
                        try {
                            $this->authService->assignRole($user, $roleSlug, $authContext);
                        } catch (\InvalidArgumentException $e) {
                            Log::warning("Skipping invalid system role: {$roleSlug}", ['error' => $e->getMessage()]);
                        }
                    }
                }

                return $user->refresh();
            });

            if (!$user->hasVerifiedEmail()) {
                try {
                    $user->sendEmailVerificationNotification();
                    Log::info('[CustomUserManagementController] Email verification sent to new user', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('[CustomUserManagementController] Failed to send email verification', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($data['send_credentials'] ?? false) {
                Log::info('User credentials need to be sent', ['user_id' => $user->id]);
            }

            return LandingResponse::success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'created_at' => $user->created_at,
                ],
            ], trans_message('landing.custom_users.created'), 201);
        } catch (QueryException $e) {
            if ($this->isEmailUniqueViolation($e)) {
                return LandingResponse::error(trans_message('landing_users.admin_panel_email_exists'), 409);
            }

            Log::error('Database error creating user with custom roles', [
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.create_error'), 500);
        } catch (ValidationException $e) {
            return LandingResponse::error(
                trans_message('landing.validation_error'),
                422,
                $e->errors()
            );
        } catch (\Throwable $e) {
            Log::error('Error creating user with custom roles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.create_error'), 500);
        }
    }

    private function isEmailUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $message = $exception->getMessage();

        return in_array($sqlState, ['23505', '23000'], true)
            && (
                str_contains($message, 'users_email_unique')
                || str_contains($message, 'users_email_lower_unique')
                || str_contains($message, 'users.email')
            );
    }

    public function getAvailableRoles(Request $request): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return $this->organizationContextMissingResponse();
        }

        try {
            $systemRoles = $this->roleScanner->getAllRoles()
                ->reject(fn (array $role, string $slug): bool => $slug === 'organization_owner')
                ->filter(fn (array $role): bool => $this->rolePayloadFormatter->isAssignableSystemRole($role))
                ->map(fn (array $role, string $slug): array => $this->rolePayloadFormatter->formatSystemRole($slug, $role))
                ->sortBy('name', SORT_NATURAL)
                ->values()
                ->toArray();
            $customRoles = collect([]);

            try {
                $customRoles = $this->customRoleService->getOrganizationRoles($organizationId);
            } catch (\Throwable $e) {
                Log::warning('Custom roles not available yet', ['error' => $e->getMessage()]);
            }

            return LandingResponse::success([
                'system_roles' => $systemRoles,
                'custom_roles' => $customRoles
                    ->map(fn ($role): array => $this->rolePayloadFormatter->formatCustomRole($role))
                    ->values()
                    ->toArray(),
                'organization_id' => $organizationId,
            ], trans_message('landing.custom_users.roles_loaded'));
        } catch (\Throwable $e) {
            Log::error('Error getting available roles', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.roles_load_error'), 500);
        }
    }

    public function updateUserCustomRoles(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'custom_role_ids' => 'required|array',
            'custom_role_ids.*' => 'integer|exists:organization_custom_roles,id',
        ]);

        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return $this->organizationContextMissingResponse();
        }

        try {
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return LandingResponse::error(trans_message('landing.custom_users.user_not_found'), 404);
            }

            if (!$user->organizations()->where('organization_user.organization_id', $organizationId)->exists()) {
                return LandingResponse::error(trans_message('landing.custom_users.user_not_in_organization'), 403);
            }

            $authContext = AuthorizationContext::getOrganizationContext($organizationId);
            $actor = $request->user();

            $this->customRoleService->syncUserRoles(
                $user,
                $data['custom_role_ids'],
                $authContext,
                $actor instanceof User ? $actor : null
            );

            return LandingResponse::success(null, trans_message('landing.custom_users.roles_updated'));
        } catch (\Throwable $e) {
            Log::error('Error updating user custom roles', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.roles_update_error'), 500);
        }
    }

    public function grantOrganizationOwner(Request $request, int $userId): JsonResponse
    {
        try {
            $user = $this->userService->grantOrganizationOwner($userId, $request);

            return LandingResponse::success([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
                'role_slug' => 'organization_owner',
            ], trans_message('landing_users.owner_granted'));
        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), $e->getCode() > 0 ? $e->getCode() : 400);
        } catch (\Throwable $e) {
            Log::error('Error granting organization owner role', [
                'user_id' => $userId,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'requested_by' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing_users.owner_grant_error'), 500);
        }
    }

    public function assignCustomRole(Request $request, int $userId, int $roleId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return $this->organizationContextMissingResponse();
        }

        try {
            $role = OrganizationCustomRole::findOrFail($roleId);
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return LandingResponse::error(trans_message('landing.custom_users.user_not_found'), 404);
            }

            $authContext = AuthorizationContext::getOrganizationContext($organizationId);
            $this->customRoleService->assignRoleToUser($role, $user, $authContext);

            return LandingResponse::success(null, trans_message('landing.custom_users.role_assigned'));
        } catch (\Throwable $e) {
            Log::error('Error assigning custom role', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.role_assign_error'), 500);
        }
    }

    public function unassignCustomRole(Request $request, int $userId, int $roleId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return $this->organizationContextMissingResponse();
        }

        try {
            $role = OrganizationCustomRole::findOrFail($roleId);
            $user = $this->userRepository->find($userId);

            if (!$user) {
                return LandingResponse::error(trans_message('landing.custom_users.user_not_found'), 404);
            }

            $authContext = AuthorizationContext::getOrganizationContext($organizationId);
            $revokedBy = $request->user();

            $this->authService->revokeRole(
                $user,
                $role->slug,
                $authContext,
                $revokedBy instanceof User ? $revokedBy : null
            );

            return LandingResponse::success(null, trans_message('landing.custom_users.role_unassigned'));
        } catch (\Throwable $e) {
            Log::error('Error unassigning custom role', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.role_unassign_error'), 500);
        }
    }

    public function getUserLimits(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $limits = $this->subscriptionLimitsService->getUserLimitsData($user);

            return LandingResponse::success($limits, trans_message('landing.custom_users.limits_loaded'));
        } catch (\Throwable $e) {
            Log::error('Error getting user limits', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing.custom_users.limits_load_error'), 500);
        }
    }

    private function organizationContextMissingResponse(): JsonResponse
    {
        return LandingResponse::error(trans_message('landing.organization_context_missing'), 400);
    }
}
