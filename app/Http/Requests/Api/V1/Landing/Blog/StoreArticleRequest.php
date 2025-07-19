<?php

namespace App\Http\Requests\Api\V1\Landing\Blog;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Blog\BlogArticleStatusEnum;
use Illuminate\Validation\Rules\Enum;

class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_articles,slug',
            'category_id' => 'required|exists:blog_categories,id',
            'excerpt' => 'nullable|string|max:500',
            'content' => 'required|string',
            'featured_image' => 'nullable|string',
            'gallery_images' => 'nullable|array',
            'gallery_images.*' => 'string',
            
            'meta_title' => 'nullable|string|max:60',
            'meta_description' => 'nullable|string|max:160',
            'meta_keywords' => 'nullable|array',
            'meta_keywords.*' => 'string|max:50',
            'og_title' => 'nullable|string|max:60',
            'og_description' => 'nullable|string|max:200',
            'og_image' => 'nullable|string',
            
            'status' => ['required', new Enum(BlogArticleStatusEnum::class)],
            'published_at' => 'nullable|date',
            'scheduled_at' => 'nullable|date|after:now',
            
            'is_featured' => 'nullable|boolean',
            'allow_comments' => 'nullable|boolean',
            'is_published_in_rss' => 'nullable|boolean',
            'noindex' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
            
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Заголовок статьи обязателен',
            'title.max' => 'Заголовок не должен превышать 255 символов',
            'slug.unique' => 'Такой URL уже существует',
            'category_id.required' => 'Категория обязательна',
            'category_id.exists' => 'Выбранная категория не существует',
            'content.required' => 'Содержимое статьи обязательно',
            'meta_title.max' => 'Meta заголовок не должен превышать 60 символов',
            'meta_description.max' => 'Meta описание не должно превышать 160 символов',
            'og_title.max' => 'OG заголовок не должен превышать 60 символов',
            'og_description.max' => 'OG описание не должно превышать 200 символов',
            'scheduled_at.after' => 'Дата публикации должна быть в будущем',
        ];
    }
} 