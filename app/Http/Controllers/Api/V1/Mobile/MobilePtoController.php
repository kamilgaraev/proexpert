<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\ActOnMobilePtoDesignPackageRequest;
use App\Http\Requests\Api\V1\Mobile\ActOnMobilePtoExecutiveDocumentRequest;
use App\Http\Requests\Api\V1\Mobile\ListMobilePtoDesignPackagesRequest;
use App\Http\Requests\Api\V1\Mobile\ShowMobilePtoDesignPackageRequest;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobilePtoService;
use Illuminate\Http\JsonResponse;

final class MobilePtoController extends Controller
{
    public function __construct(private readonly MobilePtoService $service) {}

    public function designPackages(ListMobilePtoDesignPackagesRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }
        $result = $this->service->designPackages(
            $actor,
            (int) $request->attributes->get('current_organization_id'),
            $request->validated(),
        );

        return MobileResponse::paginated(
            $result['items'],
            $result['meta'],
            trans_message('design_management.messages.packages_loaded'),
        );
    }

    public function showDesignPackage(ShowMobilePtoDesignPackageRequest $request, int $packageId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }

        return MobileResponse::success($this->service->designPackage(
            $actor,
            (int) $request->attributes->get('current_organization_id'),
            $packageId,
        ));
    }

    public function actOnDesignPackage(ActOnMobilePtoDesignPackageRequest $request, int $packageId, string $action): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }

        return MobileResponse::success($this->service->actOnDesignPackage(
            $actor,
            (int) $request->attributes->get('current_organization_id'),
            $packageId,
            $action,
            $request->validated('comment'),
        ));
    }

    public function actOnExecutiveDocument(ActOnMobilePtoExecutiveDocumentRequest $request, int $documentId, string $action): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }

        return MobileResponse::success($this->service->actOnExecutiveDocument(
            $actor,
            (int) $request->attributes->get('current_organization_id'),
            $documentId,
            $action,
            $request->validated(),
        ));
    }
}
