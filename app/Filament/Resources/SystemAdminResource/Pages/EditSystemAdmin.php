<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAdminResource\Pages;

use App\Filament\Resources\SystemAdminResource;
use App\Models\SystemAdmin;
use App\Services\Security\SystemAdminRoleService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditSystemAdmin extends EditRecord
{
    protected static string $resource = SystemAdminResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->guardRole($data['role'] ?? null);

        return $data;
    }

    protected function guardRole(?string $role): void
    {
        $user = Auth::guard('system_admin')->user();

        if (!$user instanceof SystemAdmin || !$role) {
            throw ValidationException::withMessages([
                'role' => 'Не удалось определить роль администратора.',
            ]);
        }

        $roleService = app(SystemAdminRoleService::class);

        if (!$roleService->roleExists($role)) {
            throw ValidationException::withMessages([
                'role' => 'Выбрана несуществующая роль.',
            ]);
        }

        if (!$user->isSuperAdmin() && !$roleService->canManageRole($user, $role)) {
            throw ValidationException::withMessages([
                'role' => 'Недостаточно прав для назначения этой роли.',
            ]);
        }
    }
}
