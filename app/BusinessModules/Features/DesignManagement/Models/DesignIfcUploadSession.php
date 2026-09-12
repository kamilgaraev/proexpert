<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DesignIfcUploadSession extends Model
{
    protected $table = 'design_ifc_upload_sessions';

    protected $fillable = [
        'id', 'organization_id', 'project_id', 'package_id', 'user_id', 'file_identity',
        's3_upload_id', 'source_path', 'original_name', 'mime_type', 'size_bytes',
        'part_size_bytes', 'parts_count', 'uploaded_parts', 'completion', 'payload',
        'status', 'completed_version_id', 'expires_at', 'cleaned_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'part_size_bytes' => 'integer',
        'parts_count' => 'integer',
        'uploaded_parts' => 'array',
        'completion' => 'array',
        'payload' => 'array',
        'expires_at' => 'datetime',
        'cleaned_at' => 'datetime',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    public function completedVersion(): BelongsTo
    {
        return $this->belongsTo(DesignArtifactVersion::class, 'completed_version_id');
    }
}
