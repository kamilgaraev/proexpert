<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetMappingRequest;
use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class BudgetMappingController extends BudgetingAdminController
{
    public function __construct(private readonly BudgetCatalogService $service)
    {
    }

    public function articleMappings(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->articleMappings($this->user($request), $request->query()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function storeArticleMapping(BudgetMappingRequest $request): JsonResponse
    {
        try {
            $mapping = $this->service->storeArticleMapping($this->user($request), $request->validated());

            return AdminResponse::success($this->service->mappingToArray($mapping->load('article')), trans_message('budgeting.mappings.updated'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }
}
