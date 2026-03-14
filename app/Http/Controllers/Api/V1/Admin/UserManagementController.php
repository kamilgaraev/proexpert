<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UserManagement\StoreForemanRequest;
use App\Http\Requests\Api\V1\Admin\UserManagement\UpdateForemanRequest;
use App\Http\Resources\Api\V1\Admin\User\ForemanUserResource;
use App\Http\Responses\AdminResponse;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserManagementController extends Controller
{
    protected UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
        $this->middleware('subscription.limit:max_users')->only('store');
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->query('per_page', 15);
            $includeAllTypes = $request->boolean('include_all_types');

            $usersPaginator = $includeAllTypes
                ? $this->userService->getUsersForCurrentOrg($request, $perPage)
                : $this->userService->getForemenForCurrentOrg($request, $perPage);

            return AdminResponse::success(ForemanUserResource::collection($usersPaginator));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@index', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.list_error'), 500);
        }
    }

    public function store(StoreForemanRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $roleSlug = $validated['role_slug'] ?? 'foreman';
            unset($validated['role_slug']);

            $user = $this->userService->createForeman($validated, $request, $roleSlug);

            if ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
                if ($user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    $user->save();
                } else {
                    Log::error('[UserManagementController@store] Failed to upload avatar for user.', [
                        'user_id' => $user->id,
                    ]);
                }
            }

            $user = $this->userService->findOrganizationUserById($user->id, $request) ?? $user;

            return AdminResponse::success(new ForemanUserResource($user), trans_message('user.created'), 201);
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@store', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.create_error'), 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $user = $this->userService->findOrganizationUserById((int) $id, $request);
            if (!$user) {
                return AdminResponse::error(trans_message('user.not_found'), 404);
            }

            return AdminResponse::success(new ForemanUserResource($user));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@show', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.show_error'), 500);
        }
    }

    public function update(UpdateForemanRequest $request, string $id): JsonResponse
    {
        try {
            $user = $this->userService->updateOrganizationUser((int) $id, $request->validated(), $request);

            $avatarChanged = false;

            if ($request->has('remove_avatar') && $request->boolean('remove_avatar')) {
                if ($user->deleteImage('avatar_path')) {
                    $avatarChanged = true;
                } else {
                    Log::warning('[UserManagementController@update] Failed to delete avatar from storage for user.', [
                        'user_id' => $user->id,
                    ]);
                }
            } elseif ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
                if ($user->uploadImage($request->file('avatar'), 'avatar_path', 'avatars', 'public')) {
                    $avatarChanged = true;
                } else {
                    Log::error('[UserManagementController@update] Failed to upload new avatar for user.', [
                        'user_id' => $user->id,
                    ]);

                    return AdminResponse::error(trans_message('user.avatar_upload_error'), 500);
                }
            }

            if ($avatarChanged) {
                $user->save();
            }

            $user = $this->userService->findOrganizationUserById((int) $id, $request) ?? $user;

            return AdminResponse::success(new ForemanUserResource($user), trans_message('user.updated'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@update', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.update_error'), 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->userService->deleteForeman((int) $id, $request);

            return AdminResponse::success(null, trans_message('user.deleted'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@destroy', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.delete_error'), 500);
        }
    }

    public function block(Request $request, string $id): JsonResponse
    {
        try {
            $this->userService->blockOrganizationUser((int) $id, $request);

            return AdminResponse::success(null, trans_message('user.blocked'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@block', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.block_error'), 500);
        }
    }

    public function unblock(Request $request, string $id): JsonResponse
    {
        try {
            $this->userService->unblockOrganizationUser((int) $id, $request);

            return AdminResponse::success(null, trans_message('user.unblocked'));
        } catch (BusinessLogicException $e) {
            return AdminResponse::error($e->getMessage(), $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('Error in UserManagementController@unblock', [
                'id' => $id,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return AdminResponse::error(trans_message('user.unblock_error'), 500);
        }
    }
}
