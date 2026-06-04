<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;

use function trans_message;

class SystemRoleController extends Controller
{
    public function __construct(
        protected RoleScanner $roleScanner,
        protected RolePayloadFormatter $rolePayloadFormatter
    ) {
    }

    public function index(): JsonResponse
    {
        $roles = $this->roleScanner->getAllRoles();

        $formattedRoles = $roles
            ->map(fn (array $role, string $slug): array => $this->rolePayloadFormatter->formatSystemRole($slug, $role))
            ->values();

        return LandingResponse::success($formattedRoles, trans_message('landing.system_roles.loaded'));
    }
}
