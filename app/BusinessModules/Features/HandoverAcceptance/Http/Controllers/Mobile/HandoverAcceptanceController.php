<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Controllers\Mobile;

use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceFindingResource;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\AcceptanceScopeResource;
use App\BusinessModules\Features\HandoverAcceptance\Services\HandoverAcceptanceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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
            $scopes = $this->service->listScopes(
                (int) $request->attributes->get('current_organization_id'),
                $request->only(['project_id'])
            );

            return MobileResponse::success([
                'items' => AcceptanceScopeResource::collection($scopes)->resolve(),
                'meta' => [
                    'total' => $scopes->count(),
                    'project_id' => $request->integer('project_id') ?: null,
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'index');
        }
    }

    public function storeFinding(Request $request, int $session): JsonResponse
    {
        try {
            $validated = $this->validated($request, [
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
                'severity' => ['required', 'string', Rule::in(['minor', 'major', 'critical'])],
                'create_quality_defect' => ['required', 'boolean'],
                'quality_defect_inspection_required' => ['required_if:create_quality_defect,true', 'boolean'],
            ]);

            return MobileResponse::success(
                new AcceptanceFindingResource($this->service->addFinding(
                    $this->service->findSession((int) $request->attributes->get('current_organization_id'), $session),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('handover_acceptance.messages.finding_created'),
                201
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('handover_acceptance.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'finding.store');
        }
    }

    public function resolveFinding(Request $request, int $finding): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['resolution_comment' => ['required', 'string', 'max:2000']]);

            return MobileResponse::success(new AcceptanceFindingResource($this->service->resolveFinding(
                $this->service->findFinding((int) $request->attributes->get('current_organization_id'), $finding),
                (int) $request->user()?->id,
                $validated
            )));
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('handover_acceptance.errors.validation_failed'),
                422,
                $exception->errors()
            );
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'finding.resolve');
        }
    }

    public function readyForReinspection(Request $request, int $scope): JsonResponse
    {
        return $this->scopeAction($request, $scope, fn ($model) => $this->service->markReadyForReinspection($model), 'ready_for_reinspection');
    }

    public function start(Request $request, int $scope): JsonResponse
    {
        return $this->scopeAction($request, $scope, fn ($model) => $this->service->startScope($model), 'start');
    }

    public function accept(Request $request, int $scope): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['comment' => ['nullable', 'string', 'max:1000']]);

            return $this->scopeAction(
                $request,
                $scope,
                fn ($model) => $this->service->acceptScope($model, (int) $request->user()?->id, $validated['comment'] ?? null),
                'accept'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('handover_acceptance.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    public function handover(Request $request, int $scope): JsonResponse
    {
        return $this->scopeAction(
            $request,
            $scope,
            fn ($model) => $this->service->handoverScope($model, (int) $request->user()?->id),
            'handover'
        );
    }

    public function reject(Request $request, int $scope): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

            return $this->scopeAction(
                $request,
                $scope,
                fn ($model) => $this->service->rejectScope($model, (int) $request->user()?->id, $validated['reason']),
                'reject'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('handover_acceptance.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    public function reopen(Request $request, int $scope): JsonResponse
    {
        try {
            $validated = $this->validated($request, ['reason' => ['required', 'string', 'max:1000']]);

            return $this->scopeAction(
                $request,
                $scope,
                fn ($model) => $this->service->reopenScope($model, (int) $request->user()?->id, $validated['reason']),
                'reopen'
            );
        } catch (ValidationException $exception) {
            return MobileResponse::error(
                trans_message('handover_acceptance.errors.validation_failed'),
                422,
                $exception->errors()
            );
        }
    }

    private function scopeAction(Request $request, int $scope, callable $action, string $logAction): JsonResponse
    {
        try {
            return MobileResponse::success(new AcceptanceScopeResource($action(
                $this->service->findScope((int) $request->attributes->get('current_organization_id'), $scope)
            )));
        } catch (DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, $logAction);
        }
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('handover_acceptance.mobile_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('handover_acceptance.errors.action_failed'), 500);
    }

    private function validated(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules, $this->validationMessages());

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    private function validationMessages(): array
    {
        return [
            'title.required' => trans_message('handover_acceptance.validation.title_required'),
            'severity.required' => trans_message('handover_acceptance.validation.severity_required'),
            'severity.in' => trans_message('handover_acceptance.validation.severity_invalid'),
            'create_quality_defect.required' => trans_message('handover_acceptance.validation.create_quality_defect_required'),
            'quality_defect_inspection_required.required_if' => trans_message('handover_acceptance.validation.quality_defect_inspection_required'),
            'resolution_comment.required' => trans_message('handover_acceptance.validation.resolution_comment_required'),
            'reason.required' => trans_message('handover_acceptance.validation.reason_required'),
        ];
    }
}
