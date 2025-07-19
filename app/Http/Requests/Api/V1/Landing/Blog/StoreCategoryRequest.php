<?php

namespace App\Http\Requests\Api\V1\Landing\Blog;

use Illuminate\Foundation\Http\FormRequest;

class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:blog_categories,slug',
            'description' => 'nullable|string|max:1000',
            'meta_title' => 'nullable|string|max:60',
            'meta_description' => 'nullable|string|max:160',
            'color' => 'nullable|string|regex:/^#[0-9a-fA-F]{6}$/',
            'image' => 'nullable|string',
            'sort_order' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Название категории обязательно',
            'name.max' => 'Название не должно превышать 255 символов',
            'slug.unique' => 'Такой URL уже существует',
            'description.max' => 'Описание не должно превышать 1000 символов',
            'meta_title.max' => 'Meta заголовок не должен превышать 60 символов',
            'meta_description.max' => 'Meta описание не должно превышать 160 символов',
            'color.regex' => 'Цвет должен быть в формате #RRGGBB',
            'sort_order.min' => 'Порядок сортировки не может быть отрицательным',
        ];
    }
} 