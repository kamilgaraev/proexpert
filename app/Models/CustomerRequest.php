<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class CustomerRequest extends Model
{
    protected $fillable = [
        'organization_id',
        'author_user_id',
        'project_id',
        'contract_id',
        'title',
        'request_type',
        'body',
        'attachments',
        'due_date',
        'status',
        'metadata',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
        'due_date' => 'date',
        'resolved_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(CustomerPortalComment::class, 'commentable');
    }
}
