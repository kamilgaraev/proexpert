<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class EstimateRevisionOperation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estimate_id' => 'integer',
            'organization_id' => 'integer',
            'actor_id' => 'integer',
            'source_version_id' => 'integer',
            'target_version_id' => 'integer',
            'dispatch_attempts' => 'integer',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function payload(): array
    {
        return [
            'id' => $this->id,
            'estimate_id' => $this->estimate_id,
            'status' => $this->status,
            'operation_type' => $this->operation_type,
            'target_version_id' => $this->target_version_id,
            'message' => trans_message('estimate.'.($this->operation_type === 'restore' ? 'restore' : 'revision').'_operation_'.$this->status),
            'error_code' => $this->error_code,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
