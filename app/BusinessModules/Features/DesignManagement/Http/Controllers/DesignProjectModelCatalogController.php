<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Http\Requests\ListDesignProjectModelsRequest;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectModelCatalog;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;

final class DesignProjectModelCatalogController extends Controller
{
    public function __invoke(ListDesignProjectModelsRequest $request, DesignProjectModelCatalog $catalog): JsonResponse
    {
        try {
            $result = $catalog->paginate($request->user(), (int) $request->attributes->get('current_organization_id'), $request->integer('project_id'), $request->integer('page', 1), $request->string('search')->toString());

            return AdminResponse::paginated($result['data'], $result['pagination'], trans_message('design_bim.messages.catalog_loaded'));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        }
    }
}
