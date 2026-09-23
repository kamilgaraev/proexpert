<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Requests\Api\V1\Mobile\GetMobileMyActionsRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileMyActionsService;
use Illuminate\Http\JsonResponse;

final class MobileMyActionsController extends Controller
{
    public function __construct(private readonly MobileMyActionsService $service) {}

    public function index(GetMobileMyActionsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $paginator = $this->service->paginate(
            $request->user(),
            (int) $request->attributes->get('current_organization_id'),
            isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 20),
        );

        return MobileResponse::paginated($paginator->items(), [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ]);
    }
}
