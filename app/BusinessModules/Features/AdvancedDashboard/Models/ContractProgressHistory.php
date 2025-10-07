<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Contract;
use App\Models\User;

class ContractProgressHistory extends Model
{
    protected $table = 'contract_progress_history';

    protected $fillable = [
        'contract_id',
        'progress',
        'notes',
        'updated_by',
        'recorded_at',
    ];

    protected $casts = [
        'progress' => 'decimal:2',
        'recorded_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}

