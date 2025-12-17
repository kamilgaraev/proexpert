<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\ConstructionJournalEntry;
use App\Models\User;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Illuminate\Support\Facades\DB;

class JournalApprovalService
{
    /**
     * Отправить запись на утверждение
     */
    public function submitForApproval(ConstructionJournalEntry $entry): ConstructionJournalEntry
    {
        if (!$entry->status->canSubmit()) {
            throw new \DomainException('Запись не может быть отправлена на утверждение в текущем статусе');
        }

        // Валидация: проверить что заполнены обязательные поля
        $this->validateEntryForSubmission($entry);

        $entry->submit();
        
        // Отправить событие
        event(new \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted($entry));

        return $entry->fresh();
    }

    /**
     * Утвердить запись
     */
    public function approve(ConstructionJournalEntry $entry, User $approver): ConstructionJournalEntry
    {
        if (!$entry->status->canApprove()) {
            throw new \DomainException('Запись не может быть утверждена в текущем статусе');
        }

        if (!$this->canApprove($approver, $entry)) {
            throw new \DomainException('У вас нет прав на утверждение этой записи');
        }

        return DB::transaction(function () use ($entry, $approver) {
            $entry->approve($approver);

            // Обновить прогресс связанной задачи графика
            $entry->updateScheduleProgress();

            // Отправить событие
            event(new \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved($entry));

            // Уведомить создателя записи
            $entry->createdBy->notify(new \App\Notifications\Journal\JournalEntryApprovedNotification($entry));

            return $entry->fresh(['approvedBy', 'scheduleTask']);
        });
    }

    /**
     * Отклонить запись
     */
    public function reject(ConstructionJournalEntry $entry, User $approver, string $reason): ConstructionJournalEntry
    {
        if (!$entry->status->canReject()) {
            throw new \DomainException('Запись не может быть отклонена в текущем статусе');
        }

        if (!$this->canApprove($approver, $entry)) {
            throw new \DomainException('У вас нет прав на отклонение этой записи');
        }

        if (empty($reason)) {
            throw new \InvalidArgumentException('Необходимо указать причину отклонения');
        }

        return DB::transaction(function () use ($entry, $approver, $reason) {
            $entry->reject($approver, $reason);

            // Отправить событие
            event(new \App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryRejected($entry, $reason));

            // Уведомить создателя записи
            $entry->createdBy->notify(new \App\Notifications\Journal\JournalEntryRejectedNotification($entry, $reason));

            return $entry->fresh(['approvedBy']);
        });
    }

    /**
     * Проверить может ли пользователь утверждать запись
     */
    public function canApprove(User $user, ConstructionJournalEntry $entry): bool
    {
        // Нельзя утверждать свою собственную запись
        if ($entry->created_by_user_id === $user->id) {
            return false;
        }

        // Проверить что пользователь из той же организации
        $journal = $entry->journal;
        if ($journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        // Проверить права доступа
        return $user->can('construction-journal.approve');
    }

    /**
     * Валидировать запись перед отправкой на утверждение
     */
    protected function validateEntryForSubmission(ConstructionJournalEntry $entry): void
    {
        $errors = [];

        if (empty($entry->work_description)) {
            $errors[] = 'Необходимо указать описание выполненных работ';
        }

        if (!$entry->entry_date) {
            $errors[] = 'Необходимо указать дату записи';
        }

        // Проверить что есть хотя бы один объем работ
        if ($entry->workVolumes()->count() === 0) {
            $errors[] = 'Необходимо указать хотя бы один объем выполненных работ';
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException('Запись не готова к отправке на утверждение: ' . implode('; ', $errors));
        }
    }

    /**
     * Получить статистику по утверждениям для пользователя
     */
    public function getApprovalStats(User $user): array
    {
        $journal = $user->current_organization_id 
            ? \App\Models\ConstructionJournal::where('organization_id', $user->current_organization_id)->first()
            : null;

        if (!$journal) {
            return [
                'pending_count' => 0,
                'approved_today' => 0,
                'rejected_today' => 0,
            ];
        }

        $pendingCount = \App\Models\ConstructionJournalEntry::where('journal_id', $journal->id)
            ->where('status', JournalEntryStatusEnum::SUBMITTED)
            ->count();

        $approvedToday = \App\Models\ConstructionJournalEntry::where('journal_id', $journal->id)
            ->where('status', JournalEntryStatusEnum::APPROVED)
            ->whereDate('approved_at', today())
            ->count();

        $rejectedToday = \App\Models\ConstructionJournalEntry::where('journal_id', $journal->id)
            ->where('status', JournalEntryStatusEnum::REJECTED)
            ->whereDate('approved_at', today())
            ->count();

        return [
            'pending_count' => $pendingCount,
            'approved_today' => $approvedToday,
            'rejected_today' => $rejectedToday,
        ];
    }
}

