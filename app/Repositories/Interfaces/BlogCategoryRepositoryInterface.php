<?php

namespace App\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface BlogCategoryRepositoryInterface extends BaseRepositoryInterface
{
    public function getActiveCategories(): Collection;
    
    public function getCategoriesWithArticleCount(): Collection;
    
    public function findBySlug(string $slug);
} 