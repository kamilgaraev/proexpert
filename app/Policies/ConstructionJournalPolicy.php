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
    private function hasModulePermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->hasPermission("construction-journal.{$permission}")) {
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

        return $this->hasModulePermission($user, ['view', '*']);
    }

    /**
     * Просмотр конкретного журнала
     */
    public function view(User $user, ConstructionJournal $journal): bool
    {
        // Проверить что журнал принадлежит текущей организации пользователя
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $this->hasModulePermission($user, ['view', '*']);
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

        return $this->hasModulePermission($user, ['create', '*']);
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

        return $this->hasModulePermission($user, ['edit', '*']);
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

        return $this->hasModulePermission($user, ['delete', '*']);
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

        return $this->hasModulePermission($user, ['export', '*']);
    }
}

