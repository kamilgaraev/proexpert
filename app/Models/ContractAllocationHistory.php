<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAllocationHistory extends Model
{
    use HasFactory;

    protected $table = 'contract_allocation_history';

    protected $fillable = [
        'allocation_id',
        'contract_id',
        'project_id',
        'action',
        'old_values',
        'new_values',
        'reason',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    /**
     * Распределение
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(ContractProjectAllocation::class, 'allocation_id');
    }

    /**
     * Контракт
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Проект
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Пользователь
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope для фильтрации по действию
     */
    public function scopeAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    /**
     * Scope для фильтрации по контракту
     */
    public function scopeForContract($query, int $contractId)
    {
        return $query->where('contract_id', $contractId);
    }

    /**
     * Scope для фильтрации по проекту
     */
    public function scopeForProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }
}

