<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetLineReplaceRequest;
use App\BusinessModules\Features\Budgeting\Services\BudgetLineService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class BudgetLineController extends BudgetingAdminController
{
    public function __construct(private readonly BudgetLineService $service)
    {
    }

    public function index(Request $request, string $versionUuid): JsonResponse
    {
        try {
            return AdminResponse::success([
                'items' => $this->service->lines($this->user($request), $versionUuid, $request->query()),
            ], trans_message('budgeting.lines.loaded'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function replace(BudgetLineReplaceRequest $request, string $versionUuid): JsonResponse
    {
        try {
            return AdminResponse::success([
                'items' => $this->service->replace($this->user($request), $versionUuid, $request->validated()['lines']),
            ], trans_message('budgeting.lines.updated'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }
}
