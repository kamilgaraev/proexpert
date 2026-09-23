<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers\Mobile;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Http\Requests\ChangeStatusRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\MobileSiteRequestAssignmentRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\MobileSiteRequestFileUploadRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\MobileSiteRequestIndexRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\MobileSiteRequestCalendarRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\MobileCreateSiteRequestFromTemplateRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\StoreSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestGroupRequest;
use App\BusinessModules\Features\SiteRequests\Http\Requests\UpdateSiteRequestRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestCalendarEventResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestTemplateResource;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestHistory;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestCalendarService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestTemplateService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestWorkflowService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
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
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function index(MobileSiteRequestIndexRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $filters = $request->validated();
            $scope = $filters['scope'] ?? 'own';
            $perPage = (int) ($filters['per_page'] ?? 15);
            unset($filters['scope'], $filters['per_page']);
            $requests = $this->service->paginateMobile($user, $organizationId, $perPage, $filters, $scope);

            return MobileResponse::paginated(
                $requests->getCollection()
                    ->map(fn (SiteRequest $siteRequest) => $this->makeSiteRequestListPayload(
                        $siteRequest,
                        $request,
                        $user,
                        $organizationId
                    ))
                    ->values()
                    ->all(),
                [
                    'current_page' => $requests->currentPage(),
                    'per_page' => $requests->perPage(),
                    'total' => $requests->total(),
                    'last_page' => $requests->lastPage(),
                ]
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), $this->domainStatus($e, 422));
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

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);

            if (! $siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (! $this->canAccessRequest($siteRequest, $user, $organizationId)) {
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

    public function assignees(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        /** @var User|null $user */
        $user = auth()->user();
        if ($organizationId <= 0 || ! $user) {
            return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
        }
        $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);
        if (! $siteRequest) {
            return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
        }
        try {
            return MobileResponse::success($this->service->mobileAssignees($siteRequest, $user));
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), $this->domainStatus($exception, 403));
        }
    }

    public function assign(MobileSiteRequestAssignmentRequest $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        /** @var User|null $user */
        $user = auth()->user();
        if ($organizationId <= 0 || ! $user) {
            return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
        }
        $validated = $request->validated();
        $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);
        if (! $siteRequest) {
            return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
        }
        try {
            $updated = $this->service->assign($siteRequest, (int) $user->id, (int) $validated['assigned_user_id']);
            return MobileResponse::success(
                $this->makeSiteRequestPayload($updated, $request, $user, $organizationId),
                trans_message('site_requests::mobile.assign_success')
            );
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), $this->domainStatus($exception, 403));
        }
    }

    public function files(Request $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        /** @var User|null $user */
        $user = auth()->user();
        if ($organizationId <= 0 || ! $user) {
            return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
        }
        $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);
        if (! $siteRequest) {
            return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
        }
        try {
            return MobileResponse::success($this->service->mobileFiles($siteRequest, $user));
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), $this->domainStatus($exception, 403));
        }
    }

    public function uploadFile(MobileSiteRequestFileUploadRequest $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        /** @var User|null $user */
        $user = auth()->user();
        if ($organizationId <= 0 || ! $user) {
            return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
        }
        $validated = $request->validated();
        $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);
        if (! $siteRequest) {
            return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
        }
        try {
            return MobileResponse::success(
                $this->service->uploadMobileFile($siteRequest, $user, $validated['file']),
                trans_message('site_requests::mobile.file_uploaded'),
                201
            );
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), $this->domainStatus($exception, 403));
        }
    }

    public function deleteFile(Request $request, int $id, int $fileId): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        /** @var User|null $user */
        $user = auth()->user();
        if ($organizationId <= 0 || ! $user) {
            return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
        }
        $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);
        if (! $siteRequest) {
            return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
        }
        try {
            $this->service->deleteMobileFile($siteRequest, $user, $fileId);
            return MobileResponse::success(null, trans_message('site_requests::mobile.file_deleted'));
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), $this->domainStatus($exception, 403));
        }
    }

    private function domainStatus(\DomainException $exception, int $default): int
    {
        $code = $exception->getCode();
        return $code >= 400 && $code <= 599 ? $code : $default;
    }

    public function store(StoreSiteRequestRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $validated = $request->validated();
            $idempotencyKey = $request->header('Idempotency-Key');

            if (isset($validated['materials']) && is_array($validated['materials'])) {
                $group = $this->service->createMobileBatch($organizationId, (int) $user->id, $validated, $idempotencyKey);
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

            $siteRequest = $this->service->createMobile(
                $organizationId,
                (int) $user->id,
                $validated,
                $idempotencyKey
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

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);

            if (! $siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (! $siteRequest->belongsToUser((int) $user->id)) {
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

    public function updateGroup(UpdateSiteRequestGroupRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $group = $this->service->findGroup($id, $organizationId, (int) $user->id);

            if (! $group) {
                return MobileResponse::error(trans_message('site_requests::mobile.group_not_found'), 404);
            }

            if ((int) $group->user_id !== (int) $user->id) {
                return MobileResponse::error(trans_message('site_requests::mobile.group_edit_only_own'), 403);
            }

            if ($group->status !== SiteRequestStatusEnum::DRAFT) {
                return MobileResponse::error(trans_message('site_requests::mobile.group_not_editable'), 422);
            }

            $updatedGroup = $this->service->updateGroup($group, (int) $user->id, $request->validated());
            $updatedGroup->load(['requests.project', 'requests.user', 'requests.assignedUser', 'requests.group']);
            $primaryRequest = $updatedGroup->requests->sortBy('id')->first();

            return MobileResponse::success(
                [
                    'primary_request' => $primaryRequest
                        ? $this->makeSiteRequestPayload($primaryRequest, $request, $user, $organizationId)
                        : null,
                    'group_id' => $updatedGroup->id,
                    'updated_count' => $updatedGroup->requests->count(),
                    'request_ids' => $updatedGroup->requests->pluck('id')->values()->all(),
                ],
                trans_message('site_requests::mobile.group_update_success')
            );
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
        } catch (\Exception $e) {
            Log::error('site_requests.mobile.update_group.error', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return MobileResponse::error(trans_message('site_requests::mobile.group_update_error'), 500);
        }
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            /** @var User|null $user */
            $user = auth()->user();

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);

            if (! $siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (! $siteRequest->belongsToUser((int) $user->id)) {
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

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);

            if (! $siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (! $siteRequest->belongsToUser((int) $user->id)) {
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

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);

            if (! $siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (! $siteRequest->belongsToUser((int) $user->id)) {
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

            if ($organizationId <= 0 || ! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->service->find($id, $organizationId, (int) $user->id);

            if (! $siteRequest) {
                return MobileResponse::error(trans_message('site_requests::mobile.not_found'), 404);
            }

            if (! $this->canAccessRequest($siteRequest, $user, $organizationId)) {
                return MobileResponse::error(trans_message('site_requests::mobile.access_denied'), 403);
            }

            $nextStatus = (string) $request->input('status');
            $availableStatuses = collect(
                $this->getAvailableTransitionsForUser($siteRequest, $user, $organizationId)
            )->pluck('status');

            if (! $availableStatuses->contains($nextStatus)) {
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

    public function createFromTemplate(MobileCreateSiteRequestFromTemplateRequest $request, int $templateId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) auth()->id();

            if ($organizationId <= 0) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $validated = $request->validated();

            /** @var User|null $user */
            $user = auth()->user();

            if (! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $siteRequest = $this->templateService->createFromMobileTemplate(
                $templateId,
                $organizationId,
                $userId,
                $validated['project_id']
            );

            return MobileResponse::success(
                $this->makeSiteRequestPayload($siteRequest, $request, $user, $organizationId),
                trans_message('site_requests::mobile.from_template_success'),
                201
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
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

    public function calendar(MobileSiteRequestCalendarRequest $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            if ($organizationId <= 0) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            /** @var User|null $user */
            $user = auth()->user();

            if (! $user) {
                return MobileResponse::error(trans_message('site_requests::mobile.no_organization'), 400);
            }

            $validated = $request->validated();

            $events = $this->calendarService->getMobileCalendarEvents(
                $user,
                $organizationId,
                Carbon::parse($validated['start_date']),
                Carbon::parse($validated['end_date']),
                isset($validated['project_id']) ? (int) $validated['project_id'] : null,
            );

            return MobileResponse::success(SiteRequestCalendarEventResource::collection($events));
        } catch (BusinessLogicException $e) {
            return MobileResponse::error($e->getMessage(), $e->getCode() ?: 403);
        } catch (\DomainException $e) {
            return MobileResponse::error($e->getMessage(), 422);
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

            return MobileResponse::success($this->service->mobileMeta($organizationId));
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
        $siteRequest->loadMissing([
            'project',
            'user',
            'assignedUser',
            'files',
            'calendarEvent',
            'history.user',
            'group',
        ]);

        $siteRequest->group?->load([
            'requests' => static fn ($query) => $query
                ->visibleToActor((int) $user->id)
                ->with(['user', 'assignedUser']),
        ]);
        $payload = $this->makeMobileResourcePayload($siteRequest, $request);
        $payload['available_transitions'] = $this->getAvailableTransitionsForUser($siteRequest, $user, $organizationId);
        $payload['history'] = $this->makeHistoryPayload($siteRequest);
        $payload['group_context'] = $this->makeGroupPayload($siteRequest);

        return $payload;
    }

    private function makeSiteRequestListPayload(
        SiteRequest $siteRequest,
        Request $request,
        User $user,
        int $organizationId
    ): array {
        $siteRequest->loadMissing([
            'project',
            'user',
            'assignedUser',
            'group',
        ]);

        $payload = $this->makeMobileResourcePayload($siteRequest, $request);
        $payload['available_transitions'] = $this->getAvailableTransitionsForUser($siteRequest, $user, $organizationId);

        return $payload;
    }

    private function makeMobileResourcePayload(SiteRequest $siteRequest, Request $request): array
    {
        $payload = (new SiteRequestResource($siteRequest))->resolve($request);

        unset(
            $payload['purchaseRequests'],
            $payload['purchaseOrders']
        );

        return $payload;
    }

    private function makeHistoryPayload(SiteRequest $siteRequest): array
    {
        return $siteRequest->history
            ->map(function (SiteRequestHistory $historyItem) {
                $oldStatus = $historyItem->old_value['status'] ?? null;
                $newStatus = $historyItem->new_value['status'] ?? null;

                return [
                    'id' => $historyItem->id,
                    'action' => $historyItem->action,
                    'action_label' => $this->historyActionLabel($historyItem->action),
                    'notes' => $historyItem->notes,
                    'created_at' => $historyItem->created_at?->toIso8601String(),
                    'user' => [
                        'id' => $historyItem->user?->id,
                        'name' => $historyItem->user?->name ?: trans_message('site_requests::mobile.system_user'),
                    ],
                    'old_status' => $oldStatus,
                    'old_status_label' => $this->statusLabel($oldStatus),
                    'new_status' => $newStatus,
                    'new_status_label' => $this->statusLabel($newStatus),
                ];
            })
            ->values()
            ->all();
    }

    private function makeGroupPayload(SiteRequest $siteRequest): ?array
    {
        if (! $siteRequest->relationLoaded('group') || ! $siteRequest->group) {
            return null;
        }

        $group = $siteRequest->group;
        $requests = $group->requests ?? collect([$siteRequest]);

        return [
            'id' => $group->id,
            'title' => $group->title,
            'description' => $group->description,
            'status' => $group->status?->value,
            'status_label' => $group->status?->label(),
            'status_color' => $group->status?->color(),
            'request_count' => $requests->count(),
            'items' => $requests
                ->map(function (SiteRequest $groupRequest) use ($siteRequest) {
                    return [
                        'id' => $groupRequest->id,
                        'title' => $groupRequest->title,
                        'status' => $groupRequest->status->value,
                        'status_label' => $groupRequest->status->label(),
                        'material_name' => $groupRequest->material_name,
                        'material_quantity' => $groupRequest->material_quantity,
                        'material_unit' => $groupRequest->material_unit,
                        'notes' => $groupRequest->notes,
                        'request_type' => $groupRequest->request_type->value,
                        'request_type_label' => $groupRequest->request_type->label(),
                        'is_current' => $groupRequest->id === $siteRequest->id,
                        'assigned_user' => $groupRequest->assignedUser ? [
                            'id' => $groupRequest->assignedUser->id,
                            'name' => $groupRequest->assignedUser->name,
                        ] : null,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    private function historyActionLabel(string $action): string
    {
        return match ($action) {
            SiteRequestHistory::ACTION_CREATED => trans_message('site_requests::mobile.history_created'),
            SiteRequestHistory::ACTION_UPDATED => trans_message('site_requests::mobile.history_updated'),
            SiteRequestHistory::ACTION_STATUS_CHANGED => trans_message('site_requests::mobile.history_status_changed'),
            SiteRequestHistory::ACTION_ASSIGNED => trans_message('site_requests::mobile.history_assigned'),
            SiteRequestHistory::ACTION_UNASSIGNED => trans_message('site_requests::mobile.history_unassigned'),
            SiteRequestHistory::ACTION_FILE_UPLOADED => trans_message('site_requests::mobile.history_file_uploaded'),
            SiteRequestHistory::ACTION_FILE_DELETED => trans_message('site_requests::mobile.history_file_deleted'),
            SiteRequestHistory::ACTION_DELETED => trans_message('site_requests::mobile.history_deleted'),
            SiteRequestHistory::ACTION_RESTORED => trans_message('site_requests::mobile.history_restored'),
            default => $action,
        };
    }

    private function statusLabel(?string $status): ?string
    {
        if (! is_string($status) || $status === '') {
            return null;
        }

        return SiteRequestStatusEnum::tryFrom($status)?->label();
    }

    private function canAccessRequest(SiteRequest $siteRequest, User $user, int $organizationId): bool
    {
        return $siteRequest->belongsToUser((int) $user->id)
            || $siteRequest->isAssignedTo((int) $user->id)
            || $this->hasSiteRequestPermission($user, $organizationId, 'site_requests.view')
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

            if (! is_string($requiredPermission) || $requiredPermission === '') {
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
            if (! is_string($grantedPermission) || $grantedPermission === '') {
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
