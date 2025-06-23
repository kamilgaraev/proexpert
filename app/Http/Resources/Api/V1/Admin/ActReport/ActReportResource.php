<?php

namespace App\Http\Resources\Api\V1\Admin\ActReport;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_number' => $this->report_number,
            'title' => $this->title,
            'format' => $this->format,
            'file_size' => $this->getFileSizeFormatted(),
            'is_expired' => $this->isExpired(),
            'download_url' => $this->when(
                !$this->isExpired(), 
                fn() => $this->getDownloadUrl()
            ),
            'expires_at' => $this->expires_at?->format('Y-m-d H:i:s'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata,
            'performance_act' => $this->whenLoaded('performanceAct', function () {
                return [
                    'id' => $this->performanceAct->id,
                    'act_document_number' => $this->performanceAct->act_document_number,
                    'act_date' => $this->performanceAct->act_date?->format('Y-m-d'),
                    'amount' => $this->performanceAct->amount,
                    'is_approved' => $this->performanceAct->is_approved,
                    'contract' => $this->whenLoaded('performanceAct.contract', function () {
                        return [
                            'id' => $this->performanceAct->contract->id,
                            'contract_number' => $this->performanceAct->contract->contract_number,
                            'project' => $this->whenLoaded('performanceAct.contract.project', function () {
                                return [
                                    'id' => $this->performanceAct->contract->project->id,
                                    'name' => $this->performanceAct->contract->project->name,
                                ];
                            }),
                            'contractor' => $this->whenLoaded('performanceAct.contract.contractor', function () {
                                return [
                                    'id' => $this->performanceAct->contract->contractor->id,
                                    'name' => $this->performanceAct->contract->contractor->name,
                                ];
                            })
                        ];
                    })
                ];
            })
        ];
    }
} 