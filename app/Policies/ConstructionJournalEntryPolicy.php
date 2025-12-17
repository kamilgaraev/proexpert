<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;

class ConstructionJournalEntryPolicy
{
    /**
     * Просмотр записи журнала
     */
    public function view(User $user, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;
        
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $user->can('construction-journal.view');
    }

    /**
     * Создание записи в журнале
     */
    public function create(User $user, ConstructionJournal $journal): bool
    {
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        // Нельзя создавать записи в закрытом журнале
        if (!$journal->canBeEdited()) {
            return false;
        }

        return $user->can('construction-journal.create');
    }

    /**
     * Обновление записи
     */
    public function update(User $user, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;
        
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        // Можно редактировать только свои записи или если есть право редактирования всех
        $isOwner = $entry->created_by_user_id === $user->id;
        $canEditAll = $user->can('construction-journal.edit_all');

        if (!$isOwner && !$canEditAll) {
            return false;
        }

        // Нельзя редактировать утвержденные или отклоненные записи
        if (!$entry->canBeEdited()) {
            return false;
        }

        return $user->can('construction-journal.edit');
    }

    /**
     * Удаление записи
     */
    public function delete(User $user, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;
        
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        // Можно удалять только свои записи или если есть право удаления всех
        $isOwner = $entry->created_by_user_id === $user->id;
        $canDeleteAll = $user->can('construction-journal.delete_all');

        if (!$isOwner && !$canDeleteAll) {
            return false;
        }

        // Нельзя удалять утвержденные записи
        if (!$entry->canBeEdited()) {
            return false;
        }

        return $user->can('construction-journal.delete');
    }

    /**
     * Утверждение/отклонение записи
     */
    public function approve(User $user, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;
        
        // Проверить что журнал принадлежит текущей организации
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        // Нельзя утверждать свою собственную запись
        if ($entry->created_by_user_id === $user->id) {
            return false;
        }

        return $user->can('construction-journal.approve');
    }
}

