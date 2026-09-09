<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractTypeProfileQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use function trans_message;

final class ContractTypeProfileController extends Controller
{
    public function index(Request $request, ContractTypeProfileQuery $query): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = max(1, min(100, $request->integer('per_page', 50)));
        $result = $query->get((int) $request->attributes->get('current_organization_id'), $page, $perPage);

        return AdminResponse::success($result['items'], trans_message('legal_archive.messages.type_profiles_loaded'), 200, [
            'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $result['total']],
        ]);
    }
}
