<?php

declare(strict_types=1);

namespace App\Http\Requests\Blog;

use App\Http\Responses\LandingResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class PublicBlogArticlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tag_slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[\pL\pN_-]+$/u'],
            'category_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2147483647'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:24'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tag_slug' => trans_message('blog_cms.filter_tag'),
            'category_id' => trans_message('blog_cms.filter_category'),
            'search' => trans_message('blog_cms.filter_search'),
            'page' => trans_message('blog_cms.filter_page'),
            'per_page' => trans_message('blog_cms.filter_per_page'),
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(LandingResponse::error(
            trans_message('blog_cms.invalid_filters'),
            422,
            $validator->errors()->toArray(),
        ));
    }
}
