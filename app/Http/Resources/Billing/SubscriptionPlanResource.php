<?php

namespace App\Http\Resources\Billing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @OA\Schema(
 *     schema="SubscriptionPlanResource",
 *     title="Subscription Plan Resource",
 *     description="Ресурс тарифного плана",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Старт"),
 *     @OA\Property(property="slug", type="string", example="start"),
 *     @OA\Property(property="description", type="string", example="Для индивидуальных предпринимателей..."),
 *     @OA\Property(property="price", type="number", format="float", example=499.00),
 *     @OA\Property(property="currency", type="string", example="RUB"),
 *     @OA\Property(property="duration_in_days", type="integer", example=30),
 *     @OA\Property(property="max_foremen", type="integer", nullable=true, example=1),
 *     @OA\Property(property="max_projects", type="integer", nullable=true, example=1),
 *     @OA\Property(property="max_storage_gb", type="integer", nullable=true, example=1),
 *     @OA\Property(property="features", type="array", @OA\Items(type="string"), example={"Фича 1", "Фича 2"}),
 *     @OA\Property(property="display_order", type="integer", example=1)
 * )
 */
class SubscriptionPlanResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'price' => (float)$this->price, // Убедимся, что это float
            'currency' => $this->currency,
            'duration_in_days' => $this->duration_in_days,
            'max_foremen' => $this->max_foremen,
            'max_projects' => $this->max_projects,
            'max_storage_gb' => $this->max_storage_gb,
            'features' => $this->features, // Already an AsArrayObject, should cast to array
            'display_order' => $this->display_order,
        ];
    }
} 