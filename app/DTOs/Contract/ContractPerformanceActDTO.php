<?php

namespace App\DTOs\Contract; // Помещаем в общую папку DTO для контрактов

class ContractPerformanceActDTO
{
    public function __construct(
        // contract_id будет браться из маршрута или устанавливаться в сервисе
        public readonly ?string $act_document_number,
        public readonly string $act_date, // Y-m-d format
        public readonly ?string $description,
        public readonly bool $is_approved = true, // По умолчанию одобрен при создании, если не указано иное
        public readonly ?string $approval_date, // Y-m-d format, если is_approved = true
        public readonly array $completed_works = [] // Массив выполненных работ с количествами
    ) {}

    public function toArray(): array
    {
        return [
            'act_document_number' => $this->act_document_number,
            'act_date' => $this->act_date,
            'description' => $this->description,
            'is_approved' => $this->is_approved,
            'approval_date' => $this->is_approved ? ($this->approval_date ?? now()->toDateString()) : null,
        ];
    }

    /**
     * Получить данные для синхронизации работ
     * Формат: [completed_work_id => ['included_quantity' => X, 'included_amount' => Y, 'notes' => Z]]
     */
    public function getCompletedWorksForSync(): array
    {
        $syncData = [];
        foreach ($this->completed_works as $work) {
            $syncData[$work['completed_work_id']] = [
                'included_quantity' => $work['included_quantity'],
                'included_amount' => $work['included_amount'],
                'notes' => $work['notes'] ?? null,
            ];
        }
        return $syncData;
    }
} 