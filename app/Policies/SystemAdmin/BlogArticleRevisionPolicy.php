<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogArticleRevision;
use App\Models\SystemAdmin;

class BlogArticleRevisionPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.revisions.view');
    }

    public function view(SystemAdmin $systemAdmin, BlogArticleRevision $revision): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.revisions.view');
    }

    public function restore(SystemAdmin $systemAdmin, BlogArticleRevision $revision): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.revisions.restore');
    }
}
