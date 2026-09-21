<?php

declare(strict_types=1);

namespace App\Models;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PtoWorkspaceTask extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETED = 'completed';

    public const ORIGIN_AUTOMATIC = 'automatic';

    public const ORIGIN_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'project_id',
        'source_key',
        'title',
        'responsible_user_id',
        'due_on',
        'status',
        'origin',
        'document_set_id',
        'requirement_id',
        'created_by_user_id',
        'completed_at',
    ];

    protected $casts = [
        'due_on' => 'date',
        'completed_at' => 'datetime',
        'requirement_id' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function documentSet(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocumentSet::class, 'document_set_id');
    }
}
