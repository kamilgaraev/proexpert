<?php

namespace App\Enums\Blog;

enum BlogArticleStatusEnum: string
{
    case DRAFT = 'draft';
    case PUBLISHED = 'published';
    case SCHEDULED = 'scheduled';
    case ARCHIVED = 'archived';
} 