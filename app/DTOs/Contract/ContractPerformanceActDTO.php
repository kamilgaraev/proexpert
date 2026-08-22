<?php

namespace App\DTOs\Contract; // Помещаем в общую папку DTO для контрактов

class ContractPerformanceActDTO
{
    public function __construct(
        // contract_id и project_id будут браться из маршрута или устанавливаться в сервисе
        public readonly ?int $project_id,
        public readonly ?string $act_document_number,
        public readonly string $act_date, // Y-m-d format
        public readonly ?string $description,
        public readonly bool $is_approved = false,
        public readonly ?string $approval_date = null, // Y-m-d format, если is_approved = true
        public readonly array $completed_works = [], // Массив выполненных работ с количествами
        public readonly float $amount = 0, // Сумма акта (рассчитывается автоматически)
        public readonly mixed $pdf_file = null, // PDF файл акта (UploadedFile или null)
        public readonly ?string $currency = null,
        public readonly bool $completedWorksProvided = false,
        public readonly bool $partialUpdate = false,
        public readonly array $providedFields = [],
    ) {}

    public function toArray(): array
    {
        $data = [
            'project_id' => $this->project_id,
            'act_document_number' => $this->act_document_number,
            'act_date' => $this->act_date,
            'amount' => $this->amount,
            'description' => $this->description,
            'is_approved' => $this->is_approved,
            'approval_date' => $this->is_approved ? ($this->approval_date ?? now()->toDateString()) : null,
        ];
        if ($this->currency !== null) {
            $data['currency'] = strtoupper($this->currency);
        }

        if ($this->partialUpdate) {
            $providedFields = $this->providedFields;
            if (in_array('approval_date', $providedFields, true)) {
                $data['approval_date'] = $this->approval_date;
            }
            if (in_array('is_approved', $providedFields, true)) {
                $providedFields[] = 'approval_date';
            }
            $provided = array_fill_keys($providedFields, true);
            $data = array_intersect_key($data, $provided);
        }

        return $data;
    }

    /**
     * Получить данные для синхронизации работ
     * Формат: [completed_work_id => ['included_quantity' => X, 'included_amount' => Y, 'notes' => Z]]
     */
    public function getCompletedWorksForSync(): array
    {
        $syncData = [];
        foreach ($this->completed_works as $work) {
            $currency = $work['currency'] ?? $this->currency;
            $syncData[$work['completed_work_id']] = [
                'included_quantity' => $work['included_quantity'],
                'included_amount' => $work['included_amount'] ?? 0,
                'currency' => is_string($currency) && trim($currency) !== ''
                    ? strtoupper($currency)
                    : null,
                'notes' => $work['notes'] ?? null,
            ];
        }

        return $syncData;
    }
}
