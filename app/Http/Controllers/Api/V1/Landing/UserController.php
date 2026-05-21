<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\User\StoreAdminRequest;
use App\Http\Requests\Api\V1\Landing\User\UpdateAdminRequest;
use App\Http\Resources\Api\V1\Landing\AdminUserResource;
use App\Http\Responses\LandingResponse;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

class UserController extends Controller
{
    public function __construct(private readonly UserService $userService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $admins = $this->userService->getAdminsForCurrentOrg($request);
            $this->loadRoleAssignments($admins);

            return LandingResponse::success(
                AdminUserResource::collection($admins)->resolve($request)
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_list_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_list_error', $request);
        }
    }

    public function store(StoreAdminRequest $request): JsonResponse
    {
        try {
            $admin = $this->userService->createAdmin($request->validated(), $request);

            return LandingResponse::success(
                new AdminUserResource($admin),
                trans_message('landing_users.admin_created'),
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
            return $this->businessError($exception, 'landing_users.admin_create_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_create_error', $request);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $admin = $this->userService->findAdminById((int) $id, $request);

            if (! $admin) {
                return LandingResponse::error(
                    trans_message('landing_users.admin_not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return LandingResponse::success(new AdminUserResource($admin));
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_show_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_show_error', $request);
        }
    }

    public function update(UpdateAdminRequest $request, string $id): JsonResponse
    {
        try {
            $updatedAdmin = $this->userService->updateAdmin((int) $id, $request->validated(), $request);

            return LandingResponse::success(
                new AdminUserResource($updatedAdmin),
                trans_message('landing_users.admin_updated')
            );
        } catch (ValidationException $exception) {
            report($exception);

            return LandingResponse::error(
                trans_message('errors.validation_failed'),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $exception->errors()
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_update_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_update_error', $request);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $deleted = $this->userService->deleteAdmin((int) $id, $request);

            if (! $deleted) {
                return LandingResponse::error(
                    trans_message('landing_users.admin_delete_error'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return LandingResponse::success(null, trans_message('landing_users.admin_deleted'));
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_delete_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_delete_error', $request);
        }
    }

    public function adminPanelUsersIndex(Request $request): JsonResponse
    {
        try {
            $users = $this->userService->getAllAdminPanelUsersForCurrentOrg($request);
            $this->loadRoleAssignments($users);

            return LandingResponse::success(
                AdminUserResource::collection($users)->resolve($request)
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception, 'landing_users.admin_panel_list_error', $request);
        } catch (Throwable $exception) {
            return $this->serverError($exception, 'landing_users.admin_panel_list_error', $request);
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

        Log::warning('Landing user request rejected', [
            'message_key' => $messageKey,
            'status' => $status,
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
        ]);

        return LandingResponse::error(trans_message($messageKey), $status);
    }

    private function serverError(Throwable $exception, string $messageKey, Request $request): JsonResponse
    {
        Log::error('Landing user request failed', [
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
