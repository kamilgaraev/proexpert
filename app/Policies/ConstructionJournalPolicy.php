<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;

class ConstructionJournalPolicy
{
    /**
     * Просмотр списка журналов проекта
     */
    public function viewAny(User $user, Project $project): bool
    {
        // Проверить что пользователь имеет доступ к проекту
        if ($project->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $user->can('construction-journal.view');
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

        return $user->can('construction-journal.view');
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

        return $user->can('construction-journal.create');
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

        return $user->can('construction-journal.edit');
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

        return $user->can('construction-journal.delete');
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

        return $user->can('construction-journal.export');
    }
}

