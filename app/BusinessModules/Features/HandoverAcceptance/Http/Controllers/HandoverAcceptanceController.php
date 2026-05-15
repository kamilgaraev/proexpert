<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Controllers;

use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceChecklistResource;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceFindingResource;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceScopeResource;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceSessionResource;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\HandoverPackageResource;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\ProjectLocationResource;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HandoverAcceptanceController extends Controller
{
    public function __construct(private readonly HandoverAcceptanceService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(AcceptanceScopeResource::collection(
                $this->service->listScopes($this->organizationId($request), $request->only(['project_id']))
            )->resolve());
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'index');
        }
    }

    public function storeLocation(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'parent_id' => ['nullable', 'integer'],
                'location_type' => ['required', 'string', Rule::in(['project', 'building', 'section', 'floor', 'room', 'element'])],
                'name' => ['required', 'string', 'max:255'],
                'code' => ['nullable', 'string', 'max:80'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new ProjectLocationResource($this->service->createLocation($this->organizationId($request), $validated)),
                trans_message('handover_acceptance.messages.location_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'store_location');
        }
    }

    public function storeScope(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'project_location_id' => ['nullable', 'integer'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'planned_acceptance_date' => ['nullable', 'date'],
            ]);

            return AdminResponse::success(
                new AcceptanceScopeResource($this->service->createScope($this->organizationId($request), (int) $request->user()?->id, $validated)),
                trans_message('handover_acceptance.messages.scope_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'store_scope');
        }
    }

    public function storeChecklist(Request $request, int $scope): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'items' => ['required', 'array', 'min:1'],
                'items.*.title' => ['required', 'string', 'max:255'],
                'items.*.is_required' => ['nullable', 'boolean'],
                'items.*.comment' => ['nullable', 'string', 'max:1000'],
            ]);

            return AdminResponse::success(
                new AcceptanceChecklistResource($this->service->addChecklist($this->service->findScope($this->organizationId($request), $scope), $validated)),
                trans_message('handover_acceptance.messages.checklist_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'store_checklist');
        }
    }

    public function storeSession(Request $request, int $scope): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scheduled_at' => ['nullable', 'date'],
                'participant_user_ids' => ['nullable', 'array'],
                'participant_user_ids.*' => ['integer'],
            ]);

            return AdminResponse::success(
                new AcceptanceSessionResource($this->service->createSession($this->service->findScope($this->organizationId($request), $scope), (int) $request->user()?->id, $validated)),
                trans_message('handover_acceptance.messages.session_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'store_session');
        }
    }

    public function start(Request $request, int $scope): JsonResponse
    {
        return $this->scopeAction($request, $scope, fn ($model) => $this->service->startScope($model));
    }

    public function readyForReinspection(Request $request, int $scope): JsonResponse
    {
        return $this->scopeAction($request, $scope, fn ($model) => $this->service->markReadyForReinspection($model));
    }

    public function accept(Request $request, int $scope): JsonResponse
    {
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        return $this->scopeAction($request, $scope, fn ($model) => $this->service->acceptScope($model, (int) $request->user()?->id, $validated['comment'] ?? null));
    }

    public function handover(Request $request, int $scope): JsonResponse
    {
        return $this->scopeAction($request, $scope, fn ($model) => $this->service->handoverScope($model, (int) $request->user()?->id));
    }

    public function reopen(Request $request, int $scope): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->scopeAction($request, $scope, fn ($model) => $this->service->reopenScope($model, (int) $request->user()?->id, $validated['reason']));
    }

    public function storeFinding(Request $request, int $session): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'severity' => ['nullable', 'string', Rule::in(['minor', 'major', 'critical'])],
                'create_quality_defect' => ['nullable', 'boolean'],
            ]);

            return AdminResponse::success(
                new AcceptanceFindingResource($this->service->addFinding($this->service->findSession($this->organizationId($request), $session), (int) $request->user()?->id, $validated)),
                trans_message('handover_acceptance.messages.finding_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'store_finding');
        }
    }

    public function resolveFinding(Request $request, int $finding): JsonResponse
    {
        try {
            $validated = $request->validate(['resolution_comment' => ['required', 'string', 'max:2000']]);

            return AdminResponse::success(new AcceptanceFindingResource(
                $this->service->resolveFinding($this->service->findFinding($this->organizationId($request), $finding), (int) $request->user()?->id, $validated)
            ));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'resolve_finding');
        }
    }

    public function storePackage(Request $request, int $scope): JsonResponse
    {
        try {
            $validated = $request->validate([
                'title' => ['required', 'string', 'max:255'],
                'documents' => ['required', 'array', 'min:1'],
                'documents.*.title' => ['required', 'string', 'max:255'],
                'documents.*.document_type' => ['nullable', 'string', 'max:80'],
                'documents.*.is_required' => ['nullable', 'boolean'],
                'documents.*.status' => ['nullable', 'string', Rule::in(['missing', 'draft', 'approved'])],
                'documents.*.external_url' => ['nullable', 'string', 'max:1000'],
            ]);

            return AdminResponse::success(
                new HandoverPackageResource($this->service->createPackage($this->service->findScope($this->organizationId($request), $scope), (int) $request->user()?->id, $validated)),
                trans_message('handover_acceptance.messages.package_created'),
                201
            );
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'store_package');
        }
    }

    public function approvePackageDocument(Request $request, int $document): JsonResponse
    {
        try {
            $validated = $request->validate(['external_url' => ['nullable', 'string', 'max:1000']]);

            return AdminResponse::success($this->service->approveDocument($this->service->findPackageDocument($this->organizationId($request), $document), $validated));
        } catch (ValidationException $e) {
            return AdminResponse::error($e->getMessage(), 422, $e->errors());
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'approve_document');
        }
    }

    private function scopeAction(Request $request, int $scope, callable $action): JsonResponse
    {
        try {
            return AdminResponse::success(new AcceptanceScopeResource($action($this->service->findScope($this->organizationId($request), $scope))));
        } catch (DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return $this->failed($request, $e, 'scope_action');
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id', $request->user()?->current_organization_id);
    }

    private function failed(Request $request, \Throwable $e, string $action): JsonResponse
    {
        Log::error("handover_acceptance.admin.{$action}.error", [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'error' => $e->getMessage(),
        ]);

        return AdminResponse::error(trans_message('handover_acceptance.errors.action_failed'), 500);
    }
}
