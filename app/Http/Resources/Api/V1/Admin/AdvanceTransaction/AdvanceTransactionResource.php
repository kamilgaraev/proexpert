<?php

namespace App\Http\Resources\Api\V1\Admin\AdvanceTransaction;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvanceTransactionResource extends JsonResource
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
            'user_id' => $this->user_id,
            'organization_id' => $this->organization_id,
            'project_id' => $this->project_id,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'description' => $this->description,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date ? $this->document_date->format('Y-m-d') : null,
            'cost_category_id' => $this->cost_category_id,
            'balance_after' => (float) $this->balance_after,
            'reporting_status' => $this->reporting_status,
            'reported_at' => $this->reported_at ? $this->reported_at->format('Y-m-d H:i:s') : null,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
            'created_by_user_id' => $this->created_by_user_id,
            'approved_by_user_id' => $this->approved_by_user_id,
            'external_code' => $this->external_code,
            'accounting_data' => $this->accounting_data,
            'attachment_ids' => $this->attachment_ids,
            'created_at' => $this->created_at ? $this->created_at->format('Y-m-d H:i:s') : null,
            'updated_at' => $this->updated_at ? $this->updated_at->format('Y-m-d H:i:s') : null,
            
            // Связанные данные
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                ];
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->id,
                    'name' => $this->project->name,
                    'external_code' => $this->project->external_code,
                ];
            }),
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'id' => $this->createdBy->id,
                    'name' => $this->createdBy->name,
                ];
            }),
            'approved_by' => $this->whenLoaded('approvedBy', function () {
                return [
                    'id' => $this->approvedBy->id,
                    'name' => $this->approvedBy->name,
                ];
            }),
            // Можно добавить получение реальных объектов файлов, если нужно
            // 'attachments' => $this->when($this->attachment_ids, function() {
            //     return $this->getAttachments(); 
            // }),
        ];
    }
} 