<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\OrganizationRoleService;
use App\Http\Requests\Api\V1\Landing\OrganizationRole\StoreOrganizationRoleRequest;
use App\Http\Requests\Api\V1\Landing\OrganizationRole\UpdateOrganizationRoleRequest;
use App\Http\Resources\Api\V1\Landing\OrganizationRoleResource;
use App\Http\Responses\Api\V1\SuccessCreationResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use App\Http\Responses\Api\V1\NotFoundResponse;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Exceptions\BusinessLogicException;

class OrganizationRoleController extends Controller
{
    protected OrganizationRoleService $roleService;

    public function __construct(OrganizationRoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public function index(Request $request): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $roles = $this->roleService->getAllRolesForOrganization($organizationId);
            $systemRoles = $this->roleService->getSystemRolesForOrganization();

            return new SuccessResourceResponse([
                'custom_roles' => OrganizationRoleResource::collection($roles),
                'system_roles' => $systemRoles,
            ]);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при получении ролей: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function store(StoreOrganizationRoleRequest $request): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $user = Auth::user();
            
            $role = $this->roleService->createRole(
                $request->validated(),
                $organizationId,
                $user
            );

            return new SuccessCreationResponse(
                new OrganizationRoleResource($role),
                'Роль успешно создана'
            );
        } catch (BusinessLogicException $e) {
            return new ErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при создании роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function show(int $roleId, Request $request): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $roles = $this->roleService->getAllRolesForOrganization($organizationId);
            $role = $roles->where('id', $roleId)->first();
            
            if (!$role) {
                return new NotFoundResponse('Роль не найдена');
            }

            return new SuccessResourceResponse(new OrganizationRoleResource($role));
        } catch (BusinessLogicException $e) {
            return new NotFoundResponse($e->getMessage());
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при получении роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update(UpdateOrganizationRoleRequest $request, int $roleId): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $role = $this->roleService->updateRole(
                $roleId,
                $request->validated(),
                $organizationId
            );

            return new SuccessResourceResponse(
                new OrganizationRoleResource($role),
                'Роль успешно обновлена'
            );
        } catch (BusinessLogicException $e) {
            return new ErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при обновлении роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function destroy(int $roleId, Request $request): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $this->roleService->deleteRole($roleId, $organizationId);

            return new SuccessResponse(message: 'Роль успешно удалена', statusCode: Response::HTTP_NO_CONTENT);
        } catch (BusinessLogicException $e) {
            return new ErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при удалении роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function permissions(Request $request): Responsable
    {
        try {
            $permissions = $this->roleService->getPermissionsGrouped();
            return new SuccessResourceResponse($permissions);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при получении разрешений: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function assignUser(Request $request, int $roleId): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->input('user_id');
            $user = Auth::user();

            $this->roleService->assignRoleToUser($roleId, $userId, $organizationId, $user);

            return new SuccessResponse(message: 'Роль успешно назначена пользователю');
        } catch (BusinessLogicException $e) {
            return new ErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при назначении роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function removeUser(Request $request, int $roleId): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $userId = $request->input('user_id');

            $this->roleService->removeRoleFromUser($roleId, $userId, $organizationId);

            return new SuccessResponse(message: 'Роль успешно отозвана у пользователя');
        } catch (BusinessLogicException $e) {
            return new ErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при отзыве роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function duplicate(Request $request, int $roleId): Responsable
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $newName = $request->input('name');
            $user = Auth::user();

            $role = $this->roleService->duplicateRole($roleId, $organizationId, $newName, $user);

            return new SuccessCreationResponse(
                new OrganizationRoleResource($role),
                'Роль успешно скопирована'
            );
        } catch (BusinessLogicException $e) {
            return new ErrorResponse($e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            return new ErrorResponse('Ошибка при копировании роли: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
