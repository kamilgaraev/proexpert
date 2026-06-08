<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetArticleRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetPeriodRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetPeriodWorkflowRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetScenarioRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\ResponsibilityCenterRequest;
use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\BudgetPeriod;
use App\BusinessModules\Features\Budgeting\Models\BudgetScenario;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class BudgetCatalogController extends BudgetingAdminController
{
    public function __construct(private readonly BudgetCatalogService $service)
    {
    }

    public function catalogs(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->catalogs($this->user($request), $request->query()),
                trans_message('budgeting.catalogs.loaded')
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function periods(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->service->organizationId($this->user($request), $request->query());

            return AdminResponse::success($this->service->periods($organizationId, $request->query()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function storePeriod(BudgetPeriodRequest $request): JsonResponse
    {
        try {
            $period = $this->service->storePeriod($this->user($request), $request->validated());

            return AdminResponse::success($this->service->periodToArray($period), trans_message('budgeting.periods.created'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function updatePeriod(BudgetPeriodRequest $request, string $periodUuid): JsonResponse
    {
        try {
            $period = $this->service->updatePeriod($this->user($request), $periodUuid, $request->validated());

            return AdminResponse::success($this->service->periodToArray($period), trans_message('budgeting.periods.updated'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function destroyPeriod(Request $request, string $periodUuid): JsonResponse
    {
        try {
            return $this->destroyCatalog($request, $this->service->findPeriod($this->user($request), $periodUuid));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function closePeriod(BudgetPeriodWorkflowRequest $request, string $periodUuid): JsonResponse
    {
        try {
            $period = $this->service->closePeriod($this->user($request), $periodUuid, $request->validated());

            return AdminResponse::success($this->service->periodToArray($period), trans_message('budgeting.periods.closed'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function reopenPeriod(BudgetPeriodWorkflowRequest $request, string $periodUuid): JsonResponse
    {
        try {
            $period = $this->service->reopenPeriod($this->user($request), $periodUuid, $request->validated());

            return AdminResponse::success($this->service->periodToArray($period), trans_message('budgeting.periods.reopened'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function scenarios(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->service->organizationId($this->user($request), $request->query());

            return AdminResponse::success($this->service->scenarios($organizationId, $request->query()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function storeScenario(BudgetScenarioRequest $request): JsonResponse
    {
        try {
            $scenario = $this->service->storeScenario($this->user($request), $request->validated());

            return AdminResponse::success($this->service->scenarioToArray($scenario), trans_message('budgeting.scenarios.created'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function updateScenario(BudgetScenarioRequest $request, string $scenarioUuid): JsonResponse
    {
        try {
            $scenario = $this->service->updateScenario($this->user($request), $scenarioUuid, $request->validated());

            return AdminResponse::success($this->service->scenarioToArray($scenario), trans_message('budgeting.scenarios.updated'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function destroyScenario(Request $request, string $scenarioUuid): JsonResponse
    {
        try {
            return $this->destroyCatalog($request, $this->service->findScenario($this->user($request), $scenarioUuid));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function responsibilityCenters(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->service->organizationId($this->user($request), $request->query());

            return AdminResponse::success($this->service->responsibilityCenters($organizationId, $request->query()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function storeResponsibilityCenter(ResponsibilityCenterRequest $request): JsonResponse
    {
        try {
            $center = $this->service->storeResponsibilityCenter($this->user($request), $request->validated());

            return AdminResponse::success($this->service->centerToArray($center), trans_message('budgeting.cfo.created'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function updateResponsibilityCenter(ResponsibilityCenterRequest $request, string $centerUuid): JsonResponse
    {
        try {
            $center = $this->service->updateResponsibilityCenter($this->user($request), $centerUuid, $request->validated());

            return AdminResponse::success($this->service->centerToArray($center), trans_message('budgeting.cfo.updated'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function destroyResponsibilityCenter(Request $request, string $centerUuid): JsonResponse
    {
        try {
            return $this->destroyCatalog($request, $this->service->findResponsibilityCenter($this->user($request), $centerUuid));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function articles(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->service->organizationId($this->user($request), $request->query());

            return AdminResponse::success($this->service->articles($organizationId, $request->query()));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function storeArticle(BudgetArticleRequest $request): JsonResponse
    {
        try {
            $article = $this->service->storeArticle($this->user($request), $request->validated());

            return AdminResponse::success($this->service->articleToArray($article->load('mappings')), trans_message('budgeting.articles.created'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function updateArticle(BudgetArticleRequest $request, string $articleUuid): JsonResponse
    {
        try {
            $article = $this->service->updateArticle($this->user($request), $articleUuid, $request->validated());

            return AdminResponse::success($this->service->articleToArray($article->load('mappings')), trans_message('budgeting.articles.updated'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function destroyArticle(Request $request, string $articleUuid): JsonResponse
    {
        try {
            return $this->destroyCatalog($request, $this->service->findArticle($this->user($request), $articleUuid));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    private function destroyCatalog(
        Request $request,
        BudgetPeriod|BudgetScenario|ResponsibilityCenter|BudgetArticle $model
    ): JsonResponse {
        try {
            $this->service->destroySoft($model);

            return AdminResponse::success(null, trans_message('budgeting.catalogs.deleted'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }
}
