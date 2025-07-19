<?php

namespace App\Enums\Blog;

enum BlogCommentStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SPAM = 'spam';
} 