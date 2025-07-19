<?php

namespace App\Services\Blog;

use App\Models\Blog\BlogCategory;
use App\Repositories\Blog\BlogCategoryRepository;
use Illuminate\Support\Str;

class BlogCategoryService
{
    public function __construct(
        private BlogCategoryRepository $categoryRepository
    ) {}

    public function createCategory(array $data): BlogCategory
    {
        $data = $this->prepareCategoryData($data);
        
        return BlogCategory::create($data);
    }

    public function updateCategory(BlogCategory $category, array $data): BlogCategory
    {
        $data = $this->prepareCategoryData($data);
        
        $category->update($data);
        
        return $category->fresh();
    }

    public function deleteCategory(BlogCategory $category): bool
    {
        if ($category->articles()->count() > 0) {
            throw new \Exception('Нельзя удалить категорию, содержащую статьи');
        }

        return $category->delete();
    }

    public function reorderCategories(array $categoryIds): void
    {
        foreach ($categoryIds as $order => $categoryId) {
            BlogCategory::where('id', $categoryId)->update(['sort_order' => $order]);
        }
    }

    public function generateSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (BlogCategory::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function prepareCategoryData(array $data): array
    {
        if (isset($data['name']) && empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name']);
        }

        return $data;
    }
} 