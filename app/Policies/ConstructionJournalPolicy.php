<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;

class ConstructionJournalPolicy
{
    /**
     * Проверка наличия прав модуля
     */
    private function hasModulePermission(User $user, array $permissions, ?int $organizationId = null): bool
    {
        $orgId = $organizationId ?? $user->current_organization_id;
        
        if (!$orgId) {
            return false;
        }
        
        $context = ['organization_id' => $orgId];
        
        foreach ($permissions as $permission) {
            if ($user->hasPermission("construction-journal.{$permission}", $context)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Просмотр списка журналов проекта
     */
    public function viewAny(User $user, Project $project): bool
    {
        // Проверить что пользователь имеет доступ к проекту
        if ($project->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*'], $project->organization_id);
    }

    /**
     * Просмотр конкретного журнала
     */
    public function view(User $user, $model): bool
    {
        // Если передан Project (для списка журналов)
        if ($model instanceof Project) {
            if ($model->organization_id !== $user->current_organization_id) {
                return false;
            }
            return $this->hasModulePermission($user, ['view', '*'], $model->organization_id);
        }
        
        // Если передан ConstructionJournal (для конкретного журнала)
        if ($model instanceof ConstructionJournal) {
            if ($model->organization_id !== $user->current_organization_id) {
                return false;
            }
            return $this->hasModulePermission($user, ['view', '*'], $model->organization_id);
        }
        
        return false;
    }

    /**
     * Создание нового журнала
     */
    public function create(User $user, Project $project): bool
    {
        // Проверить что проект принадлежит текущей организации пользователя
        if ($project->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $this->hasModulePermission($user, ['create', '*'], $project->organization_id);
    }

    /**
     * Обновление журнала
     */
    public function update(User $user, ConstructionJournal $journal): bool
    {
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        // Можно редактировать только активные журналы
        if (!$journal->canBeEdited()) {
            return false;
        }

        return $this->hasModulePermission($user, ['edit', '*'], $journal->organization_id);
    }

    /**
     * Удаление журнала
     */
    public function delete(User $user, ConstructionJournal $journal): bool
    {
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $this->hasModulePermission($user, ['delete', '*'], $journal->organization_id);
    }

    /**
     * Экспорт документов из журнала
     */
    public function export(User $user, ConstructionJournal $journal): bool
    {
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $this->hasModulePermission($user, ['export', '*'], $journal->organization_id);
    }
}

