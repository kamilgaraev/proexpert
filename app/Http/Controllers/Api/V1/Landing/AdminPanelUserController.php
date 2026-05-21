<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\AdminPanelUser\UpdateAdminPanelUserRequest;
use App\Http\Requests\Api\V1\Landing\User\StoreAdminPanelUserRequest;
use App\Http\Resources\Api\V1\Landing\AdminPanelUserResource;
use App\Http\Responses\LandingResponse;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class AdminPanelUserController extends Controller
{
    public function __construct(private readonly UserService $userService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $users = $this->userService->getAdminPanelUsersForCurrentOrg($request);
            $this->loadRoleAssignments($users);

            return LandingResponse::success(
                AdminPanelUserResource::collection($users)->resolve($request)
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_panel_list_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_panel_list_error', $request);
        }
    }

    public function store(StoreAdminPanelUserRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            $roleSlug = (string) $validatedData['role_slug'];
            unset($validatedData['role_slug']);

            $user = $this->userService->createAdminPanelUser($validatedData, $roleSlug, $request);
            $user->load('roleAssignments');

            return LandingResponse::success(
                new AdminPanelUserResource($user),
                trans_message('landing_users.admin_panel_created'),
                Response::HTTP_CREATED
            );
        } catch (ValidationException $exception) {
            report($exception);

            return LandingResponse::error(
                trans_message('errors.validation_failed'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->errors()
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_panel_create_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_panel_create_error', $request);
        }
    }

    public function show(int $userId, Request $request): JsonResponse
    {
        try {
            $user = $this->userService->findAdminPanelUserById($userId, $request);

            if (! $user) {
                return LandingResponse::error(
                    trans_message('landing_users.admin_panel_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return LandingResponse::success(new AdminPanelUserResource($user));
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_panel_show_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_panel_show_error', $request);
        }
    }

    public function update(UpdateAdminPanelUserRequest $request, int $userId): JsonResponse
    {
        try {
            $user = $this->userService->updateAdminPanelUser($userId, $request->validated(), $request);

            return LandingResponse::success(
                new AdminPanelUserResource($user),
                trans_message('landing_users.admin_panel_updated')
            );
        } catch (ValidationException $exception) {
            report($exception);

            return LandingResponse::error(
                trans_message('errors.validation_failed'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->errors()
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_panel_update_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_panel_update_error', $request);
        }
    }

    public function destroy(int $userId, Request $request): JsonResponse
    {
        try {
            $deleted = $this->userService->deleteAdminPanelUser($userId, $request);

            if (! $deleted) {
                return LandingResponse::error(
                    trans_message('landing_users.admin_panel_delete_error'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return LandingResponse::success(null, trans_message('landing_users.admin_panel_deleted'));
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_panel_delete_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_panel_delete_error', $request);
        }
    }

    public function resendVerificationEmail(int $userId, Request $request): JsonResponse
    {
        try {
            $user = $this->userService->findAdminPanelUserById($userId, $request);

            if (! $user) {
                return LandingResponse::error(
                    trans_message('landing_users.admin_panel_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            if ($user->hasVerifiedEmail()) {
                return LandingResponse::error(
                    trans_message('landing_users.email_already_verified'),
                    Response::HTTP_BAD_REQUEST
                );
            }

            $user->sendEmailVerificationNotification();

            Log::info('Admin panel verification email resent', [
                'target_user_id' => $user->id,
                'requested_by' => Auth::id(),
            ]);

            return LandingResponse::success(
                null,
                trans_message('landing_users.verification_email_sent')
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.verification_email_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.verification_email_error', $request);
        }
    }

    private function loadRoleAssignments(mixed $users): void
    {
        try {
            $users->load('roleAssignments');
        } catch (Throwable) {
        }
    }

    private function businessError(BusinessLogicException $exception, string $messageKey, Request $request): JsonResponse
    {
        $status = $this->statusFromException($exception, Response::HTTP_BAD_REQUEST);

        Log::warning('Landing admin panel user request rejected', [
            'message_key' => $messageKey,
            'status' => $status,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
        ]);

        return LandingResponse::error(trans_message($messageKey), $status);
    }

    private function serverError(Throwable $exception, string $messageKey, Request $request): JsonResponse
    {
        Log::error('Landing admin panel user request failed', [
            'message_key' => $messageKey,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'exception' => $exception,
        ]);

        return LandingResponse::error(trans_message($messageKey), Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    private function statusFromException(Throwable $exception, int $fallback): int
    {
        $code = (int) $exception->getCode();

        return $code >= 400 && $code < 600 ? $code : $fallback;
    }
}
