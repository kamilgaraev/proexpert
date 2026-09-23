<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Controllers;

use App\BusinessModules\Features\ChangeManagement\Http\Requests\ApproveChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Http\Requests\StoreChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeClaimResource;
use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeRequestResource;
use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeRfiResource;
use App\BusinessModules\Features\ChangeManagement\Http\Resources\VariationOrderResource;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ChangeManagementController extends Controller
{
    public function __construct(private readonly ChangeManagementService $service) {}

    public function rfis(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateRfis(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status']),
                false,
                (int) $request->user()?->id,
            ), ChangeRfiResource::class);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.index');
        }
    }

    public function rfiRecipients(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate(['project_id' => ['required', 'integer', 'min:1']]);

            return AdminResponse::success(['items' => $this->service->rfiRecipients(
                (int) $validated['project_id'],
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
            )]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.recipients');
        }
    }

    public function showRfi(Request $request, int $id): JsonResponse
    {
        try {
            return AdminResponse::success(new ChangeRfiResource($this->service->findVisibleRfi(
                (int) $request->attributes->get('current_organization_id'), $id, (int) $request->user()?->id,
            )));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.show');
        }
    }

    public function storeRfi(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'rfi_number' => ['nullable', 'string', 'max:80'],
                'subject' => ['required', 'string', 'max:255'],
                'question' => ['required', 'string', 'max:5000'],
                'addressee_type' => ['nullable', 'string', 'max:80'],
                'recipient_organization_id' => ['nullable', 'integer', 'min:1'],
                'due_date' => ['nullable', 'date'],
                'response_due_date' => ['nullable', 'date'],
                'metadata' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new ChangeRfiResource($this->service->createRfi(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('change_management.messages.rfi_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.store');
        }
    }

    public function sendRfi(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['recipient_organization_id' => ['nullable', 'integer', 'min:1']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->sendRfi(
            $rfi,
            $organizationId,
            (int) $request->user()?->id,
            isset($validated['recipient_organization_id']) ? (int) $validated['recipient_organization_id'] : null,
        ));
    }

    public function answerRfi(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['answer' => ['required', 'string', 'max:5000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->answerRfi($rfi, $organizationId, (int) $request->user()?->id, $validated['answer']));
    }

    public function acceptRfi(Request $request, int $id): JsonResponse
    {
        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->acceptRfi($rfi, $organizationId, (int) $request->user()?->id));
    }

    public function requestRfiClarification(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->requestRfiClarification($rfi, $organizationId, (int) $request->user()?->id, $validated['message']));
    }

    public function closeRfi(Request $request, int $id): JsonResponse
    {
        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->closeRfi($rfi, $organizationId, (int) $request->user()?->id));
    }

    public function reassignRfi(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'recipient_organization_id' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:2000'],
            ]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->reassignRfi($rfi, $organizationId, (int) $request->user()?->id, (int) $validated['recipient_organization_id'], $validated['reason']));
    }

    public function uploadRfiAttachment(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->attachRfiFile($rfi, $organizationId, (int) $request->user()?->id, $validated['file']));
    }

    public function downloadRfiAttachment(Request $request, int $id, string $attachmentId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()?->id;
            $url = $this->service->rfiAttachmentUrl($this->service->findVisibleRfi($organizationId, $id, $userId), $organizationId, $userId, $attachmentId);
            if ($url === null) {
                return AdminResponse::error(trans_message('change_management.errors.rfi_attachment_unavailable'), 503);
            }

            return AdminResponse::success(['url' => $url, 'expires_in' => 300]);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.attachment.download');
        }
    }

    public function changes(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateChanges(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), ChangeRequestResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.index');
        }
    }

    public function storeChange(StoreChangeRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            return AdminResponse::success(
                new ChangeRequestResource($this->service->createChange(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('change_management.messages.change_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.store');
        }
    }

    public function submitChange(Request $request, int $id): JsonResponse
    {
        return $this->changeAction($request, $id, fn ($change) => $this->service->submitChange($change));
    }

    public function assessImpact(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate($this->impactRules());
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->changeAction($request, $id, fn ($change) => $this->service->assessImpact($change, $validated));
    }

    public function startInternalReview(Request $request, int $id): JsonResponse
    {
        return $this->changeAction($request, $id, fn ($change) => $this->service->startInternalReview($change));
    }

    public function startCustomerReview(Request $request, int $id): JsonResponse
    {
        return $this->changeAction($request, $id, fn ($change) => $this->service->startCustomerReview($change));
    }

    public function approveChange(ApproveChangeRequest $request, int $id): JsonResponse
    {
        $validated = $request->validated();

        return $this->changeAction(
            $request,
            $id,
            fn ($change) => $this->service->approveChange(
                $change,
                (int) $request->user()?->id,
                $validated['approved_cost_amount'],
                $validated['comment'] ?? null,
            )
        );
    }

    public function storeVariationOrder(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'variation_number' => ['required', 'string', 'max:80'],
                'amount' => ['nullable', 'numeric'],
                'schedule_delta_days' => ['nullable', 'integer'],
                'description' => ['nullable', 'string', 'max:5000'],
            ]);

            $change = $this->service->findChange((int) $request->attributes->get('current_organization_id'), $id);

            return AdminResponse::success(
                new VariationOrderResource($this->service->createVariationOrder($change, $validated)),
                trans_message('change_management.messages.variation_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'variation_orders.store');
        }
    }

    public function implementChange(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['implementation_comment' => ['nullable', 'string', 'max:3000']]);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->changeAction(
            $request,
            $id,
            fn ($change) => $this->service->implementChange($change, $validated['implementation_comment'] ?? null)
        );
    }

    public function closeChange(Request $request, int $id): JsonResponse
    {
        return $this->changeAction($request, $id, fn ($change) => $this->service->closeChange($change));
    }

    public function claims(Request $request): JsonResponse
    {
        try {
            return $this->paginated($this->service->paginateClaims(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                $request->only(['project_id', 'status'])
            ), ChangeClaimResource::class);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'claims.index');
        }
    }

    public function storeClaim(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer'],
                'change_request_id' => ['nullable', 'integer'],
                'claim_number' => ['nullable', 'string', 'max:80'],
                'title' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:5000'],
                'amount' => ['nullable', 'numeric'],
                'evidence' => ['nullable', 'array'],
            ]);

            return AdminResponse::success(
                new ChangeClaimResource($this->service->createClaim(
                    (int) $request->attributes->get('current_organization_id'),
                    (int) $request->user()?->id,
                    $validated
                )),
                trans_message('change_management.messages.claim_created'),
                201
            );
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'claims.store');
        }
    }

    private function rfiAction(Request $request, int $id, callable $callback): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $rfi = $this->service->findVisibleRfi($organizationId, $id, (int) $request->user()?->id);

            return AdminResponse::success(new ChangeRfiResource($callback($rfi, $organizationId)));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (HttpExceptionInterface $exception) {
            return AdminResponse::error($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.action');
        }
    }

    private function changeAction(Request $request, int $id, callable $callback): JsonResponse
    {
        try {
            return AdminResponse::success(new ChangeRequestResource($callback($this->service->findChange(
                (int) $request->attributes->get('current_organization_id'),
                $id
            ))));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.action');
        }
    }

    private function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        return AdminResponse::paginated($resourceClass::collection($paginator->items()), [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ]);
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('change_management.admin_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message('change_management.errors.unexpected'), 500);
    }

    private function impactRules(): array
    {
        return [
            'cost_delta' => ['nullable', 'numeric'],
            'schedule_delta_days' => ['nullable', 'integer'],
            'requires_contract_change' => ['nullable', 'boolean'],
            'requires_estimate_revision' => ['nullable', 'boolean'],
            'requires_procurement_update' => ['nullable', 'boolean'],
            'requires_customer_approval' => ['nullable', 'boolean'],
            'affected_schedule_task_ids' => ['nullable', 'array'],
            'affected_schedule_task_ids.*' => ['integer'],
            'affected_estimate_item_ids' => ['nullable', 'array'],
            'affected_estimate_item_ids.*' => ['integer'],
            'affected_contract_ids' => ['nullable', 'array'],
            'affected_contract_ids.*' => ['integer'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
