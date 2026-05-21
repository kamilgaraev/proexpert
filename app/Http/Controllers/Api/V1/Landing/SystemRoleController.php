<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Domain\Authorization\Services\RoleScanner;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\PermissionTranslationService;
use Illuminate\Http\JsonResponse;

use function trans_message;

class SystemRoleController extends Controller
{
    public function __construct(
        protected RoleScanner $roleScanner,
        protected PermissionTranslationService $permissionTranslator
    ) {
    }

    public function index(): JsonResponse
    {
        $roles = $this->roleScanner->getAllRoles();

        $formattedRoles = $roles->map(function ($role) {
            $slug = $role['slug'];

            $name = trans_message("roles.{$slug}.name");
            if ($name === "roles.{$slug}.name") {
                $name = $role['name'];
            }

            $description = trans_message("roles.{$slug}.description");
            if ($description === "roles.{$slug}.description") {
                $description = $role['description'] ?? '';
            }

            $translatedPermissions = $this->permissionTranslator->processPermissionsForFrontend([
                'system_permissions' => $role['system_permissions'] ?? [],
                'module_permissions' => $role['module_permissions'] ?? [],
                'interface_access' => $role['interface_access'] ?? [],
            ]);

            return [
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'context' => $role['context'],
                'interface' => $role['interface'],
                'permissions' => $translatedPermissions,
            ];
        })->values();

        return LandingResponse::success($formattedRoles, trans_message('landing.system_roles.loaded'));
    }
}
