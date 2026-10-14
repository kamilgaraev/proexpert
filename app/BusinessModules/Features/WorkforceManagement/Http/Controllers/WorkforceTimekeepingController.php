<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingService;
use App\BusinessModules\Features\WorkforceManagement\Http\Requests\Timekeeping\TimekeepingRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceTimekeepingController extends Controller
{
    public function __construct(private readonly TimekeepingService $service) {}

    public function index(TimekeepingRequest $request): JsonResponse
    {
        return $this->respond(function () use ($request): JsonResponse {
            $paginator = $this->service->index($this->organization($request), $request->user(), $request->validated());

            return AdminResponse::paginated($paginator->items(), ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]);
        });
    }

    public function store(TimekeepingRequest $request): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->store($this->organization($request), $request->user(), $request->validated()), null, 201));
    }

    public function show(Request $request, int $period): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->show($this->organization($request), $request->user(), $period)));
    }

    public function refresh(TimekeepingRequest $request, int $period): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->refresh($this->organization($request), $request->user(), $period, (int) $request->validated('expected_revision'))));
    }

    public function action(TimekeepingRequest $request, int $period): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->action($this->organization($request), $request->user(), $period, $request->validated())));
    }

    public function cell(TimekeepingRequest $request, int $period): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->cell($this->organization($request), $request->user(), $period, $request->validated())));
    }

    public function export(TimekeepingRequest $request, int $period): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->export($this->organization($request), $request->user(), $period, $request->validated('format')), null, 201));
    }

    public function uploadSignedFile(TimekeepingRequest $request, int $period): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->uploadSignedFile($this->organization($request), $request->user(), $period, $request->file('file'), $request->validated()), null, 201));
    }

    public function download(Request $request, int $document): JsonResponse
    {
        return $this->respond(fn () => AdminResponse::success($this->service->download($this->organization($request), $request->user(), $document)));
    }

    private function organization(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function respond(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (\DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
