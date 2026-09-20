<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Models;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkVolumeStatement extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REPLACED = 'replaced';

    protected $table = 'work_volume_statements';

    protected $fillable = [
        'organization_id', 'project_id', 'statement_key', 'version', 'based_on_statement_id', 'operation_key', 'operation_hash', 'name', 'status',
        'change_reason', 'basis_revision', 'source_file_path', 'source_file_hash',
        'approved_at', 'approved_by_user_id',
        'submitted_at', 'submitted_by_user_id',
        'review_round', 'review_history',
        'draft_version',
    ];

    protected $casts = [
        'version' => 'integer',
        'based_on_statement_id' => 'integer',
        'approved_at' => 'datetime',
        'submitted_at' => 'datetime',
        'submitted_by_user_id' => 'integer',
        'review_round' => 'integer',
        'review_history' => 'array',
        'draft_version' => 'integer',
        'source_import_id' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(WorkVolumeStatementLine::class, 'statement_id')->orderBy('id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
