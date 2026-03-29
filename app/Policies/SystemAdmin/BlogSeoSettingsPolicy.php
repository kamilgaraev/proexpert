<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogSeoSettings;
use App\Models\SystemAdmin;

class BlogSeoSettingsPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.seo.manage');
    }

    public function view(SystemAdmin $systemAdmin, BlogSeoSettings $settings): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.seo.manage');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.seo.manage');
    }

    public function update(SystemAdmin $systemAdmin, BlogSeoSettings $settings): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.seo.manage');
    }
}
