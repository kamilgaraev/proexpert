<?php

declare(strict_types=1);

namespace App\Models\Blog;

use App\Enums\Blog\BlogContextEnum;
use App\Enums\Blog\BlogRevisionTypeEnum;
use App\Models\SystemAdmin;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlogArticleRevision extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'blog_context',
        'revision_type',
        'editor_version',
        'title',
        'slug',
        'excerpt',
        'content_html',
        'editor_document',
        'featured_image',
        'gallery_images',
        'meta_title',
        'meta_description',
        'meta_keywords',
        'og_title',
        'og_description',
        'og_image',
        'structured_data',
        'category_id',
        'category_snapshot',
        'tag_ids',
        'tags_snapshot',
        'status',
        'published_at',
        'scheduled_at',
        'is_featured',
        'allow_comments',
        'is_published_in_rss',
        'noindex',
        'sort_order',
        'created_by_system_admin_id',
    ];

    protected $casts = [
        'blog_context' => BlogContextEnum::class,
        'revision_type' => BlogRevisionTypeEnum::class,
        'editor_document' => 'array',
        'gallery_images' => 'array',
        'meta_keywords' => 'array',
        'structured_data' => 'array',
        'category_snapshot' => 'array',
        'tag_ids' => 'array',
        'tags_snapshot' => 'array',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'is_featured' => 'boolean',
        'allow_comments' => 'boolean',
        'is_published_in_rss' => 'boolean',
        'noindex' => 'boolean',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(BlogArticle::class, 'article_id');
    }

    public function createdBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'created_by_system_admin_id');
    }
}
