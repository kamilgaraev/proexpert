<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $organization_id
 * @property int $document_id
 * @property string $version_number
 * @property string $file_url
 * @property string|null $comment
 * @property \Illuminate\Support\Carbon|null $uploaded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class ExecutiveDocumentVersion extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'document_id',
        'uploaded_by',
        'approved_by',
        'version_number',
        'status',
        'file_url',
        'content_hash',
        'comment',
        'uploaded_at',
        'submitted_at',
        'approved_at',
        'transmitted_at',
        'metadata',
        'profile_snapshot',
        'basis_snapshot',
        'operation_key',
        'operation_hash',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'transmitted_at' => 'datetime',
        'metadata' => 'array',
        'profile_snapshot' => 'array',
        'basis_snapshot' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocument::class, 'document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
