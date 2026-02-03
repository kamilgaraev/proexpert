<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Domain\Authorization\Services\RoleScanner;
use App\Services\PermissionTranslationService;
use Illuminate\Http\JsonResponse;

class SystemRoleController extends Controller
{
    public function __construct(
        protected RoleScanner $roleScanner,
        protected PermissionTranslationService $permissionTranslator
    ) {}

    public function index(): JsonResponse
    {
        $roles = $this->roleScanner->getAllRoles();

        $formattedRoles = $roles->map(function ($role) {
            $slug = $role['slug'];
            
            // Translate name and description
            $name = trans("roles.{$slug}.name");
            if ($name === "roles.{$slug}.name") {
                $name = $role['name'];
            }

            $description = trans("roles.{$slug}.description");
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

        return response()->json(['data' => $formattedRoles]);
    }
}
