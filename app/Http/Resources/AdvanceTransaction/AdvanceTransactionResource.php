<?php

namespace App\Http\Resources\AdvanceTransaction;

use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\User\UserResource;
use App\Http\Resources\Project\ProjectResource;
use App\Http\Resources\File\FileResource;
use App\Models\File;

class AdvanceTransactionResource extends JsonResource
{
    /**
     * Преобразовать ресурс в массив.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'organization_id' => $this->organization_id,
            'project' => new ProjectResource($this->whenLoaded('project')),
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'balance_after' => (float) $this->balance_after,
            'description' => $this->description,
            'document_number' => $this->document_number,
            'document_date' => $this->document_date ? $this->document_date->format('Y-m-d') : null,
            'reporting_status' => $this->reporting_status,
            'reported_at' => $this->reported_at ? $this->reported_at->format('Y-m-d H:i:s') : null,
            'approved_at' => $this->approved_at ? $this->approved_at->format('Y-m-d H:i:s') : null,
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'approved_by' => new UserResource($this->whenLoaded('approvedBy')),
            'external_code' => $this->external_code,
            'accounting_data' => $this->accounting_data,
            'attachments' => $this->when($this->attachment_ids, function () {
                $fileIds = explode(',', $this->attachment_ids);
                $files = File::whereIn('id', $fileIds)->get();
                return FileResource::collection($files);
            }),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
} 