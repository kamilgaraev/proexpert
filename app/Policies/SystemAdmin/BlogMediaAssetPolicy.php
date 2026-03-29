<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogMediaAsset;
use App\Models\SystemAdmin;

class BlogMediaAssetPolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.media.view');
    }

    public function view(SystemAdmin $systemAdmin, BlogMediaAsset $asset): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.media.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.media.upload');
    }

    public function update(SystemAdmin $systemAdmin, BlogMediaAsset $asset): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.media.manage');
    }

    public function delete(SystemAdmin $systemAdmin, BlogMediaAsset $asset): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.media.delete');
    }
}
