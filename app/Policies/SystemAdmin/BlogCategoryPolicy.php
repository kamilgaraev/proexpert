<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogCategory;
use App\Models\SystemAdmin;

class BlogCategoryPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.categories.manage');
    }

    public function view(SystemAdmin $systemAdmin, BlogCategory $category): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.categories.manage');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.categories.manage');
    }

    public function update(SystemAdmin $systemAdmin, BlogCategory $category): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.categories.manage');
    }

    public function delete(SystemAdmin $systemAdmin, BlogCategory $category): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.categories.manage');
    }
}
