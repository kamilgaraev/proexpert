<?php

namespace App\Http\Requests\Api\V1\Landing\Blog;

use Illuminate\Foundation\Http\FormRequest;
use App\Enums\Blog\BlogCommentStatusEnum;
use Illuminate\Validation\Rules\Enum;

class UpdateCommentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(BlogCommentStatusEnum::class)],
            'comment_ids' => 'sometimes|array',
            'comment_ids.*' => 'integer|exists:blog_comments,id',
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'Статус комментария обязателен',
            'comment_ids.array' => 'Идентификаторы комментариев должны быть массивом',
            'comment_ids.*.exists' => 'Комментарий не найден',
        ];
    }
} 