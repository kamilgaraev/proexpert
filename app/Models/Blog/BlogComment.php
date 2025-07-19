<?php

namespace App\Models\Blog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\LandingAdmin;
use App\Enums\Blog\BlogCommentStatusEnum;

class BlogComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'article_id',
        'parent_id',
        'author_name',
        'author_email',
        'author_website',
        'author_ip',
        'user_agent',
        'content',
        'status',
        'approved_at',
        'approved_by',
        'likes_count',
    ];

    protected $casts = [
        'status' => BlogCommentStatusEnum::class,
        'approved_at' => 'datetime',
        'likes_count' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(BlogArticle::class, 'article_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(LandingAdmin::class, 'approved_by');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', BlogCommentStatusEnum::APPROVED);
    }

    public function scopePending($query)
    {
        return $query->where('status', BlogCommentStatusEnum::PENDING);
    }

    public function scopeRootComments($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeReplies($query)
    {
        return $query->whereNotNull('parent_id');
    }

    public function getIsApprovedAttribute(): bool
    {
        return $this->status === BlogCommentStatusEnum::APPROVED;
    }

    public function getIsRootAttribute(): bool
    {
        return is_null($this->parent_id);
    }

    public function approve(LandingAdmin $admin): void
    {
        $this->update([
            'status' => BlogCommentStatusEnum::APPROVED,
            'approved_at' => now(),
            'approved_by' => $admin->id,
        ]);
    }

    public function reject(): void
    {
        $this->update([
            'status' => BlogCommentStatusEnum::REJECTED,
        ]);
    }

    public function markAsSpam(): void
    {
        $this->update([
            'status' => BlogCommentStatusEnum::SPAM,
        ]);
    }
} 