<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'organization_id',
        'status',
        'file_path',
        'file_name',
        'file_size',
        'file_format',
        'options',
        'stats',
        'error_message',
    ];

    protected $casts = [
        'options' => 'array',
        'stats' => 'array',
        'file_size' => 'integer',
        'user_id' => 'integer',
        'organization_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
    
    public function isProcessing(): bool
    {
        return in_array($this->status, ['parsing', 'processing']);
    }
}
