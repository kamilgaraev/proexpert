<?php

namespace App\Http\Requests\Api\V1\Admin\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use App\Models\Role;

class StoreProjectRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Доступ к контроллеру уже проверен middleware 'can:access-admin-panel'
        // Дополнительно можно проверить, что пользователь аутентифицирован, если это не гарантируется ранее
        return Auth::check(); 
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address' => 'nullable|string|max:1000',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'status' => 'required|string|in:active,completed,planned,on_hold', // Уточнить статусы
            'is_archived' => 'sometimes|boolean',
            // Добавить другие поля
        ];
    }
} 