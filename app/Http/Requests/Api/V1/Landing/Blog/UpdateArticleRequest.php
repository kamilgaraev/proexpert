<?php

namespace App\Http\Requests\Api\V1\Landing\Blog;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Blog\BlogArticleStatusEnum;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $articleId = $this->route('article')?->id ?? $this->route('id');
        
        return [
            'title' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                Rule::unique('blog_articles', 'slug')->ignore($articleId)
            ],
            'category_id' => 'sometimes|required|exists:blog_categories,id',
            'excerpt' => 'sometimes|nullable|string|max:500',
            'content' => 'sometimes|required|string',
            'featured_image' => 'sometimes|nullable|string',
            'gallery_images' => 'sometimes|nullable|array',
            'gallery_images.*' => 'string',
            
            'meta_title' => 'sometimes|nullable|string|max:60',
            'meta_description' => 'sometimes|nullable|string|max:160',
            'meta_keywords' => 'sometimes|nullable|array',
            'meta_keywords.*' => 'string|max:50',
            'og_title' => 'sometimes|nullable|string|max:60',
            'og_description' => 'sometimes|nullable|string|max:200',
            'og_image' => 'sometimes|nullable|string',
            
            'status' => ['sometimes', 'required', new Enum(BlogArticleStatusEnum::class)],
            'published_at' => 'sometimes|nullable|date',
            'scheduled_at' => 'sometimes|nullable|date|after:now',
            
            'is_featured' => 'sometimes|nullable|boolean',
            'allow_comments' => 'sometimes|nullable|boolean',
            'is_published_in_rss' => 'sometimes|nullable|boolean',
            'noindex' => 'sometimes|nullable|boolean',
            'sort_order' => 'sometimes|nullable|integer|min:0',
            
            'tags' => 'sometimes|nullable|array',
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