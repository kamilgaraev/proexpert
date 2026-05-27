<?php

declare(strict_types=1);

namespace App\Enums\Blog;

enum BlogRevisionTypeEnum: string
{
    case MANUAL = 'manual';
    case AUTOSAVE = 'autosave';
    case SCHEDULE = 'schedule';
    case PUBLISH = 'publish';
    case UNPUBLISH = 'unpublish';
    case ARCHIVE = 'archive';
    case RESTORE = 'restore';

    public function label(): string
    {
        return trans_message('blog_cms.revision_types.' . $this->value);
    }
}
