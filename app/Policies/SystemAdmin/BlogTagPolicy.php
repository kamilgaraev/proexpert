<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogTag;
use App\Models\SystemAdmin;

class BlogTagPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.tags.manage');
    }

    public function view(SystemAdmin $systemAdmin, BlogTag $tag): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.tags.manage');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.tags.manage');
    }

    public function update(SystemAdmin $systemAdmin, BlogTag $tag): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.tags.manage');
    }

    public function delete(SystemAdmin $systemAdmin, BlogTag $tag): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.tags.manage');
    }
}
