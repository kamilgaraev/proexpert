<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveRemarkStatusEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $document_id
 * @property string $body
 * @property string $severity
 * @property ExecutiveRemarkStatusEnum $status
 * @property string|null $resolution_comment
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property ExecutiveDocument $document
 */
final class ExecutiveDocumentRemark extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'document_id',
        'created_by',
        'resolved_by',
        'body',
        'severity',
        'status',
        'resolution_comment',
        'resolved_at',
    ];

    protected $casts = [
        'status' => ExecutiveRemarkStatusEnum::class,
        'resolved_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocument::class, 'document_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
