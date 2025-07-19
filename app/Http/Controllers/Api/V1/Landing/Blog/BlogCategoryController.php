<?php

namespace App\Http\Controllers\Api\V1\Landing\Blog;

use App\Http\Controllers\Controller;
use App\Models\Blog\BlogCategory;
use App\Services\Blog\BlogCategoryService;
use App\Repositories\Blog\BlogCategoryRepository;
use App\Http\Requests\Api\V1\Landing\Blog\StoreCategoryRequest;
use App\Http\Resources\Api\V1\Landing\Blog\BlogCategoryResource;
use App\Http\Responses\Api\V1\SuccessResourceResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use App\Http\Responses\Api\V1\SuccessResponse;
use Illuminate\Http\Request;

class BlogCategoryController extends Controller
{
    public function __construct(
        private BlogCategoryService $categoryService,
        private BlogCategoryRepository $categoryRepository
    ) {}

    public function index()
    {
        try {
            $categories = $this->categoryRepository->getCategoriesWithArticleCount();
            
            return new SuccessResourceResponse(
                BlogCategoryResource::collection($categories)
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при получении категорий: ' . $e->getMessage(), 500);
        }
    }

    public function store(StoreCategoryRequest $request)
    {
        try {
            $category = $this->categoryService->createCategory($request->validated());
            
            return new SuccessResourceResponse(
                new BlogCategoryResource($category),
                'Категория успешно создана'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при создании категории: ' . $e->getMessage(), 500);
        }
    }

    public function show(BlogCategory $category)
    {
        return new SuccessResourceResponse(
            new BlogCategoryResource($category)
        );
    }

    public function update(StoreCategoryRequest $request, BlogCategory $category)
    {
        try {
            $updatedCategory = $this->categoryService->updateCategory($category, $request->validated());
            
            return new SuccessResourceResponse(
                new BlogCategoryResource($updatedCategory),
                'Категория успешно обновлена'
            );
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при обновлении категории: ' . $e->getMessage(), 500);
        }
    }

    public function destroy(BlogCategory $category)
    {
        try {
            $this->categoryService->deleteCategory($category);
            
            return new SuccessResponse(null, 'Категория успешно удалена');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при удалении категории: ' . $e->getMessage(), 500);
        }
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'category_ids' => 'required|array',
            'category_ids.*' => 'integer|exists:blog_categories,id'
        ]);

        try {
            $this->categoryService->reorderCategories($request->input('category_ids'));
            
            return new SuccessResponse(null, 'Порядок категорий обновлен');
        } catch (\Exception $e) {
            return new ErrorResponse('Ошибка при изменении порядка категорий: ' . $e->getMessage(), 500);
        }
    }
} 