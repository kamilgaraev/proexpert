<?php

namespace App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct;

use App\Models\ContractPerformanceAct;
use App\Services\ActReport\ActReportWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContractPerformanceAct */
class ContractPerformanceActResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_id' => $this->contract_id,
            'project_id' => $this->project_id,
            'contract_number' => $this->whenLoaded('contract', fn() => $this->contract->number),
            'contract_date' => $this->whenLoaded('contract', fn() => $this->contract->date),
            'contract_subject' => $this->whenLoaded('contract', fn() => $this->contract->subject),
            'project_name' => $this->resolveProjectName(),
            'contractor_name' => $this->resolveContractorName(),
            'act_document_number' => $this->act_document_number,
            'act_date' => $this->act_date,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'amount' => (float) ($this->amount ?? 0),
            'description' => $this->description,
            'status' => $this->status ?? ($this->is_approved ? 'approved' : 'draft'),
            'is_approved' => (bool) $this->is_approved,
            'approval_date' => $this->approval_date,
            'created_by_user_id' => $this->created_by_user_id,
            'submitted_by_user_id' => $this->submitted_by_user_id,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_by_user_id' => $this->approved_by_user_id,
            'rejected_by_user_id' => $this->rejected_by_user_id,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'signed_file_id' => $this->signed_file_id,
            'signed_by_user_id' => $this->signed_by_user_id,
            'signed_at' => $this->signed_at?->toIso8601String(),
            'locked_by_user_id' => $this->locked_by_user_id,
            'locked_at' => $this->locked_at?->toIso8601String(),
            'is_locked' => $this->isLocked(),
            'financial_summary' => app(ActReportWorkflowService::class)->financialSummary($this->resource),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            
            // Связанные выполненные работы
            'completed_works' => $this->whenLoaded('completedWorks', function() {
                return $this->completedWorks->map(function($work) {
                    return [
                        'id' => $work->id,
                        'work_type_name' => $work->workType->name ?? 'Не указано',
                        'user_name' => $work->user->name ?? 'Не указано',
                        'total_quantity' => (float) $work->quantity,
                        'total_amount' => (float) $work->total_amount,
                        'completion_date' => $work->completion_date,
                        'included_quantity' => (float) ($work->pivot->included_quantity ?? 0),
                        'included_amount' => (float) ($work->pivot->included_amount ?? 0),
                        'notes' => $work->pivot->notes,
                    ];
                });
            }, []),

            'lines' => $this->whenLoaded('lines', function () {
                return $this->lines->map(function ($line) {
                    return [
                        'id' => $line->id,
                        'line_type' => $line->line_type,
                        'completed_work_id' => $line->completed_work_id,
                        'estimate_item_id' => $line->estimate_item_id,
                        'title' => $line->title,
                        'unit' => $line->unit,
                        'quantity' => (float) $line->quantity,
                        'unit_price' => $line->unit_price === null ? null : (float) $line->unit_price,
                        'amount' => (float) $line->amount,
                        'manual_reason' => $line->manual_reason,
                        'created_by' => $line->created_by,
                        'created_at' => $line->created_at?->toIso8601String(),
                    ];
                });
            }, []),

            'files' => $this->whenLoaded('files', function() {
                return $this->files->map(function($file) {
                    return [
                        'id' => $file->id,
                        'name' => $file->original_name,
                        'size' => $file->size,
                        'mime_type' => $file->mime_type,
                        'uploaded_by' => $file->user->name ?? 'Неизвестно',
                        'uploaded_at' => $file->created_at->toIso8601String(),
                        'description' => $file->additional_info['description'] ?? null,
                    ];
                });
            }, []),

            'files_count' => $this->whenCounted('files'),
        ];
    }

    private function resolveProjectName(): ?string
    {
        if ($this->relationLoaded('project')) {
            return $this->project?->name;
        }

        if ($this->relationLoaded('contract') && $this->contract?->relationLoaded('project')) {
            return $this->contract->project?->name;
        }

        return null;
    }

    private function resolveContractorName(): ?string
    {
        if ($this->relationLoaded('contract') && $this->contract?->relationLoaded('contractor')) {
            return $this->contract->contractor?->name;
        }

        return null;
    }
} 
