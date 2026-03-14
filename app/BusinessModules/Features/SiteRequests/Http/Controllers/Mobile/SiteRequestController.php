<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers\Mobile;

use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\PersonnelTypeEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Http\Requests\ChangeStatusRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\StoreSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCalendarEventResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCollection;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestTemplateResource;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestTemplateService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestWorkflowService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\MeasurementUnit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SiteRequestController extends Controller
{
    public function __construct(
        private readonly SiteRequestService $service,
        private readonly SiteRequestTemplateService $templateService,
        private readonly SiteRequestCalendarService $calendarService,
        private readonly SiteRequestWorkflowService $workflowService,
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $perPage = min((int) $request->input('per_page', 15), 50);
            $scope = $request->input('scope', 'own');
            $filters = $request->only([
                'status',
                'priority',
                'request_type',
                'project_id',
                'search',
            ]);

            if ($scope === 'approvals') {
                if (!$this->canReviewRequests($user, $organizationId)) {
                    return MobileResponse::error(trans_message('site_requests::mobile.approvals_access_denied'), 403);
                }

                $filters['status_in'] = [
                    SiteRequestStatusEnum::PENDING->value,
                    SiteRequestStatusEnum::IN_REVIEW->value,
                ];
            } else {
                $filters['user_id'] = (int) $user->id;
            }

            $requests = $this->service->paginate($organizationId, $perPage, $filters);

            return MobileResponse::success(new SiteRequestCollection($requests));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.index.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.index_error'), 500);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$this->canAccessRequest($siteRequest, $user, $organizationId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.access_denied'), 403);
            }

            return MobileResponse::success($this->makeSiteRequestPayload($siteRequest, $request, $user, $organizationId));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.show.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.show_error'), 500);
        }
    }

    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $validated = $request->validated();

            if (isset($validated['materials']) && is_array($validated['materials'])) {
                $items = [];

                foreach ($validated['materials'] as $material) {
                    $itemData = [
                        'material_name' => $material['name'] ?? null,
                        'material_quantity' => $material['quantity'] ?? null,
                        'material_unit' => $material['unit'] ?? null,
                        'material_id' => $material['material_id'] ?? null,
                        'note' => $material['note'] ?? null,
                    ];

                    $itemData['title'] = ($validated['title'] ?? trans_message('site_requests::mobile.default_title'))
                        . ($itemData['material_name'] ? ' - ' . $itemData['material_name'] : '');

                    $items[] = $itemData;
                }

                $group = $this->service->createBatch($organizationId, (int) $user->id, $validated, $items);
                $group->load(['requests.project', 'requests.user', 'requests.assignedUser', 'requests.group']);
                $primaryRequest = $group->requests->first();

                return MobileResponse::success(
                    [
                        'primary_request' => $primaryRequest
                            ? $this->makeSiteRequestPayload($primaryRequest, $request, $user, $organizationId)
                            : null,
                        'group_id' => $group->id,
                        'created_count' => $group->requests->count(),
                        'request_ids' => $group->requests->pluck('id')->values()->all(),
                    ],
                    trans_message('site_requests::mobile.batch_store_success'),
                    201
                );
            }

            $siteRequest = $this->service->create(
                $organizationId,
                (int) $user->id,
                $validated
            );

            return MobileResponse::success(
                $this->makeSiteRequestPayload($siteRequest, $request, $user, $organizationId),
                trans_message('site_requests::mobile.store_success'),
                201
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.store.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.store_error'), 500);
        }
    }

    public function update(UpdateSiteRequestRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser((int) $user->id)) {
                return MobileResponse::error(trans_message('site_requests::mobile.edit_only_own'), 403);
            }

            $updated = $this->service->update($siteRequest, (int) $user->id, $request->validated());

            return MobileResponse::success(
                $this->makeSiteRequestPayload($updated, $request, $user, $organizationId),
                trans_message('site_requests::mobile.update_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.update.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.update_error'), 500);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser((int) $user->id)) {
                return MobileResponse::error(trans_message('site_requests::mobile.cancel_only_own'), 403);
            }

            $updated = $this->service->cancel($siteRequest, (int) $user->id, $request->input('notes'));

            return MobileResponse::success(
                $this->makeSiteRequestPayload($updated, $request, $user, $organizationId),
                trans_message('site_requests::mobile.cancel_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.cancel.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.cancel_error'), 500);
        }
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser((int) $user->id)) {
                return MobileResponse::error(trans_message('site_requests::mobile.submit_only_own'), 403);
            }

            $updated = $this->service->submit($siteRequest, (int) $user->id);

            return MobileResponse::success(
                $this->makeSiteRequestPayload($updated, $request, $user, $organizationId),
                trans_message('site_requests::mobile.submit_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.submit.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.submit_error'), 500);
        }
    }

    public function complete(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$siteRequest->belongsToUser((int) $user->id)) {
                return MobileResponse::error(trans_message('site_requests::mobile.complete_only_own'), 403);
            }

            $updated = $this->service->complete($siteRequest, (int) $user->id, $request->input('notes'));

            return MobileResponse::success(
                $this->makeSiteRequestPayload($updated, $request, $user, $organizationId),
                trans_message('site_requests::mobile.complete_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.complete.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.complete_error'), 500);
        }
    }

    public function changeStatus(ChangeStatusRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || !$user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId);

            if (!$siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (!$this->canAccessRequest($siteRequest, $user, $organizationId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.access_denied'), 403);
            }

            $nextStatus = (string) $request->input('status');
            $availableStatuses = collect(
                $this->getAvailableTransitionsForUser($siteRequest, $user, $organizationId)
            )->pluck('status');

            if (!$availableStatuses->contains($nextStatus)) {
                return MobileResponse::error(trans_message('site_requests::mobile.transition_forbidden'), 403);
            }

            $updated = $this->service->changeStatus(
                $siteRequest,
                (int) $user->id,
                $nextStatus,
                $request->input('notes')
            );

            return MobileResponse::success(
                $this->makeSiteRequestPayload($updated, $request, $user, $organizationId),
                trans_message('site_requests::mobile.change_status_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.change_status.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.change_status_error'), 500);
        }
    }

    public function templates(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            if ($organizationId <= 0) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $templates = $this->templateService->getPopularTemplates($organizationId, 20);

            return MobileResponse::success(SiteRequestTemplateResource::collection($templates));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.templates.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.templates_error'), 500);
        }
    }

    public function createFromTemplate(Request $request, int $templateId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) auth()->id();

            if ($organizationId <= 0) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'exists:projects,id'],
            ]);

            $siteRequest = $this->templateService->createFromTemplate(
                $templateId,
                $organizationId,
                $userId,
                $validated['project_id']
            );

            return MobileResponse::success(
                new SiteRequestResource($siteRequest),
                trans_message('site_requests::mobile.from_template_success'),
                201
            );
        } catch (\InvalidArgumentException | \DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.create_from_template.error', [
                'template_id' => $templateId,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.from_template_error'), 500);
        }
    }

    public function calendar(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            if ($organizationId <= 0) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $request->validate([
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'project_id' => ['nullable', 'integer'],
            ]);

            $events = $this->calendarService->getCalendarEvents(
                $organizationId,
                Carbon::parse($request->input('start_date')),
                Carbon::parse($request->input('end_date')),
                $request->input('project_id')
            );

            return MobileResponse::success(SiteRequestCalendarEventResource::collection($events));
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.calendar.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.calendar_error'), 500);
        }
    }

    public function meta(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            if ($organizationId <= 0) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            return MobileResponse::success([
                'request_types' => SiteRequestTypeEnum::options(),
                'personnel_types' => PersonnelTypeEnum::options(),
                'equipment_types' => EquipmentTypeEnum::options(),
                'units' => MeasurementUnit::where(function ($query) use ($organizationId) {
                    $query->where('organization_id', $organizationId)
                        ->orWhere('is_system', true);
                })->get(['id', 'name', 'short_name', 'type']),
            ]);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.meta.error', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.meta_error'), 500);
        }
    }

    private function makeSiteRequestPayload(
        SiteRequest $siteRequest,
        Request $request,
        User $user,
        int $organizationId
    ): array {
        $siteRequest->loadMissing(['project', 'user', 'assignedUser', 'files', 'calendarEvent', 'group']);
        $payload = (new SiteRequestResource($siteRequest))->resolve($request);
        $payload['available_transitions'] = $this->getAvailableTransitionsForUser($siteRequest, $user, $organizationId);

        return $payload;
    }

    private function canAccessRequest(SiteRequest $siteRequest, User $user, int $organizationId): bool
    {
        return $siteRequest->belongsToUser((int) $user->id)
            || $siteRequest->isAssignedTo((int) $user->id)
            || $this->canReviewRequests($user, $organizationId);
    }

    private function canReviewRequests(User $user, int $organizationId): bool
    {
        return $this->hasAnySiteRequestPermission($user, $organizationId, [
            'site_requests.approve',
            'site_requests.assign',
            'site_requests.change_status',
            'site_requests.statistics',
        ]);
    }

    private function getAvailableTransitionsForUser(
        SiteRequest $siteRequest,
        User $user,
        int $organizationId
    ): array {
        $transitions = $this->workflowService->getAvailableTransitions($siteRequest);

        return array_values(array_filter($transitions, function (array $transition) use ($user, $organizationId) {
            $requiredPermission = $transition['required_permission'] ?? null;

            if (!is_string($requiredPermission) || $requiredPermission === '') {
                return true;
            }

            return $this->hasSiteRequestPermission($user, $organizationId, $requiredPermission);
        }));
    }

    private function hasAnySiteRequestPermission(User $user, int $organizationId, array $expectedPermissions): bool
    {
        foreach ($expectedPermissions as $expectedPermission) {
            if ($this->hasSiteRequestPermission($user, $organizationId, $expectedPermission)) {
                return true;
            }
        }

        return false;
    }

    private function hasSiteRequestPermission(User $user, int $organizationId, string $expectedPermission): bool
    {
        $context = AuthorizationContext::getOrganizationContext($organizationId);
        $permissions = $this->authorizationService->getUserPermissionsStructured($user, $context);
        $grantedPermissions = $permissions['modules']['site-requests'] ?? [];

        foreach ($grantedPermissions as $grantedPermission) {
            if (!is_string($grantedPermission) || $grantedPermission === '') {
                continue;
            }

            if ($grantedPermission === '*' || $grantedPermission === $expectedPermission) {
                return true;
            }

            if (str_ends_with($grantedPermission, '.*')) {
                $prefix = substr($grantedPermission, 0, -1);

                if (str_starts_with($expectedPermission, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
