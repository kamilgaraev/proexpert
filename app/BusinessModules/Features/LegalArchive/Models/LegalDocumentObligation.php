<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalDocumentObligation extends Model
{
    protected $fillable = ['organization_id', 'document_id', 'document_version_id', 'project_id', 'responsible_user_id', 'title', 'responsible_party', 'due_at', 'amount', 'volume', 'unit', 'status', 'completed_at', 'evidence', 'metadata'];
    protected $casts = ['due_at' => 'datetime', 'completed_at' => 'datetime', 'amount' => 'decimal:2', 'volume' => 'decimal:3', 'evidence' => 'array', 'metadata' => 'array'];
    public function document(): BelongsTo { return $this->belongsTo(LegalArchiveDocument::class, 'document_id'); }
    public function version(): BelongsTo { return $this->belongsTo(LegalArchiveDocumentVersion::class, 'document_version_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_user_id'); }
}
