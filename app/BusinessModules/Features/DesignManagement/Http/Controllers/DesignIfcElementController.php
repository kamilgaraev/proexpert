<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Services\DesignIfcElementQueryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DesignIfcElementController extends Controller
{
    public function __construct(
        private readonly DesignIfcElementQueryService $elements,
    ) {}

    public function show(Request $request, int $versionId, int $elementId): JsonResponse
    {
        try {
            return AdminResponse::success($this->elements->payload($this->elements->element(
                $this->actor($request), $this->organizationId($request), $versionId, $elementId,
            )));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        }
    }

    public function index(Request $request, int $versionId): JsonResponse
    {
        try {
            $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:200']]);
            $paginator = $this->elements->paginate(
                $this->actor($request), $this->organizationId($request), $versionId,
                (int) ($validated['per_page'] ?? config('design_management.ifc_elements_page_size', 100)),
                $validated['search'] ?? null,
            );

            return AdminResponse::success([
                'data' => $paginator->getCollection()->map(fn ($item) => $this->elements->payload($item))->values(),
                'pagination' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('design_management.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new DomainException(trans_message('design_ifc.errors.element_not_found'));
        }

        return $actor;
    }
}
