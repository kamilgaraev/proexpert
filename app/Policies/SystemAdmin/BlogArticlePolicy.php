<?php

declare(strict_types=1);

namespace App\Policies\SystemAdmin;

use App\Models\Blog\BlogArticle;
use App\Models\SystemAdmin;

class BlogArticlePolicy extends BaseSystemAdminPolicy
{
    public function viewAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.articles.view');
    }

    public function view(SystemAdmin $systemAdmin, BlogArticle $article): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.articles.view');
    }

    public function create(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.articles.create');
    }

    public function update(SystemAdmin $systemAdmin, BlogArticle $article): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.articles.update');
    }

    public function delete(SystemAdmin $systemAdmin, BlogArticle $article): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.articles.delete');
    }

    public function deleteAny(SystemAdmin $systemAdmin): bool
    {
        return $this->allows($systemAdmin, 'system_admin.blog.articles.delete');
    }
}
