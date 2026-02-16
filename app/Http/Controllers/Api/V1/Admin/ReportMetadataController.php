<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Report\CustomReportBuilderService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportMetadataController extends Controller
{
    public function __construct(
        protected CustomReportBuilderService $builderService
    ) {
    }

    /**
     * Получение уникальных значений для поля источника данных.
     * Используется для построения выпадающих списков в фильтрах.
     */
    public function getFilterValues(string $dataSource, string $field, Request $request): JsonResponse
    {
        try {
            $search = $request->query('search');
            $values = $this->builderService->getFilterValues($dataSource, $field, $search);

            return AdminResponse::success($values);
        } catch (\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return AdminResponse::error(trans_message('reports.builder.filter_values_failed'), 500);
        }
    }
}
