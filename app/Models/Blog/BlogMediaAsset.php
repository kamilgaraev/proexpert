<?php

declare(strict_types=1);

namespace App\Models\Blog;

use App\Enums\Blog\BlogContextEnum;
use App\Models\SystemAdmin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogMediaAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'blog_context',
        'filename',
        'storage_path',
        'public_url',
        'mime_type',
        'file_size',
        'width',
        'height',
        'alt_text',
        'caption',
        'focal_point',
        'usage_metadata',
        'uploaded_by_system_admin_id',
    ];

    protected $casts = [
        'blog_context' => BlogContextEnum::class,
        'file_size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'focal_point' => 'array',
        'usage_metadata' => 'array',
    ];

    public function uploadedBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'uploaded_by_system_admin_id');
    }
}
