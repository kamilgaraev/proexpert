<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\EstimatePositionItemType;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

class EstimateItemTypesController extends Controller
{
    /**
     * Получить список доступных типов позиций сметы
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $types = collect(EstimatePositionItemType::cases())->map(function ($type) {
            return [
                'value' => $type->value,
                'label' => $type->label(),
            ];
        });

        return AdminResponse::success($types->values());
    }
}
