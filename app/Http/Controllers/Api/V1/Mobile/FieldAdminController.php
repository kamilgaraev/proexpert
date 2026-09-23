<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldAdminCalendarRequest;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldAdminAttendanceRequest;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldAdminDetailRequest;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldAdminListRequest;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldAdminPaginationRequest;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldTeamBindRequest;
use App\Http\Requests\Api\V1\Mobile\FieldAdmin\FieldTeamIndexRequest;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileFieldAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final class FieldAdminController extends Controller
{
    public function __construct(private readonly MobileFieldAdminService $service) {}

    public function projectTeam(FieldTeamIndexRequest $request, int $project): JsonResponse
    {
        $result = $this->service->projectTeam(
            $this->actor($request),
            $this->organizationId($request),
            $project,
            $request->validated()
        );

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function availableProjectUsers(FieldTeamIndexRequest $request, int $project): JsonResponse
    {
        $result = $this->service->availableProjectUsers(
            $this->actor($request),
            $this->organizationId($request),
            $project,
            $request->validated()
        );

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function bindProjectUser(FieldTeamBindRequest $request, int $project, int $user): JsonResponse
    {
        return MobileResponse::success($this->service->bindProjectUser(
            $this->actor($request),
            $this->organizationId($request),
            $project,
            $user
        ));
    }

    public function employees(FieldAdminListRequest $request): JsonResponse
    {
        $result = $this->service->employees($this->actor($request), $this->organizationId($request), $request->validated());

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function employee(FieldAdminDetailRequest $request, int $employee): JsonResponse
    {
        return MobileResponse::success($this->service->employee(
            $this->actor($request),
            $this->organizationId($request),
            $employee,
            $request->validated()
        ));
    }

    public function absences(FieldAdminListRequest $request): JsonResponse
    {
        $result = $this->service->absences($this->actor($request), $this->organizationId($request), $request->validated());

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function orders(FieldAdminPaginationRequest $request): JsonResponse
    {
        $result = $this->service->orders($this->actor($request), $this->organizationId($request), $request->validated());

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function attendance(FieldAdminAttendanceRequest $request): JsonResponse
    {
        $result = $this->service->attendance($this->actor($request), $this->organizationId($request), $request->validated());

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function calendar(FieldAdminCalendarRequest $request): JsonResponse
    {
        return MobileResponse::success($this->service->calendar(
            $this->actor($request),
            $this->organizationId($request),
            $request->validated()
        ));
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new UnauthorizedHttpException('Bearer');
        }

        return $actor;
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
