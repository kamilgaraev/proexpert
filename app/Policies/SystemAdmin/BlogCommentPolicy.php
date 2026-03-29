<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogComment;
use App\Models\SystemAdmin;

class BlogCommentPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.comments.moderate');
    }

    public function view(SystemAdmin $systemAdmin, BlogComment $comment): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.comments.moderate');
    }

    public function update(SystemAdmin $systemAdmin, BlogComment $comment): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.comments.moderate');
    }

    public function delete(SystemAdmin $systemAdmin, BlogComment $comment): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.comments.moderate');
    }
}
