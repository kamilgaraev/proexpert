<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Controllers\Customer;

use App\BusinessModules\Features\ChangeManagement\Http\Requests\ApproveChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeRequestResource;
use App\BusinessModules\Features\ChangeManagement\Http\Resources\ChangeRfiResource;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\CustomerResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ChangeManagementController extends Controller
{
    public function __construct(private readonly ChangeManagementService $service) {}

    public function rfis(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'min:1'],
                'status' => ['nullable', 'string', 'in:draft,sent,answered,accepted,closed,clarification_requested'],
                'direction' => ['nullable', 'string', 'in:incoming,outgoing,drafts'],
                'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);
            $paginator = $this->service->paginateRfis(
                (int) $request->attributes->get('current_organization_id'),
                (int) ($validated['per_page'] ?? 20),
                $validated,
                true,
                (int) $request->user()?->id,
            );

            return CustomerResponse::success([
                'items' => ChangeRfiResource::collection($paginator->items())->resolve(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 403);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.index');
        }
    }

    public function rfiRecipients(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate(['project_id' => ['required', 'integer', 'min:1']]);

            return CustomerResponse::success(['items' => $this->service->rfiRecipients(
                (int) $validated['project_id'],
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
            )]);
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 403);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.recipients');
        }
    }

    public function showRfi(Request $request, int $id): JsonResponse
    {
        try {
            return CustomerResponse::success(new ChangeRfiResource($this->service->findVisibleRfi(
                (int) $request->attributes->get('current_organization_id'), $id, (int) $request->user()?->id, true,
            )));
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.show');
        }
    }

    public function storeRfi(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'project_id' => ['required', 'integer', 'min:1'],
                'recipient_organization_id' => ['nullable', 'integer', 'min:1'],
                'subject' => ['required', 'string', 'max:255'],
                'question' => ['required', 'string', 'max:5000'],
                'due_date' => ['nullable', 'date'],
            ]);

            return CustomerResponse::success(new ChangeRfiResource($this->service->createRfi(
                (int) $request->attributes->get('current_organization_id'),
                (int) $request->user()?->id,
                $validated,
            )), trans_message('change_management.messages.rfi_created'), 201);
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.store');
        }
    }

    public function sendRfi(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['recipient_organization_id' => ['required', 'integer', 'min:1']]);
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return CustomerResponse::success(new ChangeRfiResource($this->service->sendRfi(
                $this->service->findVisibleRfi($organizationId, $id, (int) $request->user()?->id, true),
                $organizationId,
                (int) $request->user()?->id,
                (int) $validated['recipient_organization_id'],
            )));
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422);
        } catch (HttpExceptionInterface $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.send');
        }
    }

    public function answerRfi(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate(['answer' => ['required', 'string', 'max:5000']]);
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return CustomerResponse::success(new ChangeRfiResource($this->service->answerRfi(
                $this->service->findVisibleRfi($organizationId, $id, (int) $request->user()?->id, true),
                $organizationId, (int) $request->user()?->id, $validated['answer'],
            )));
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 403);
        } catch (HttpExceptionInterface $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.answer');
        }
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
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        }

        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->requestRfiClarification($rfi, $organizationId, (int) $request->user()?->id, $validated['message']));
    }

    public function closeRfi(Request $request, int $id): JsonResponse
    {
        return $this->rfiAction($request, $id, fn ($rfi, int $organizationId) => $this->service->closeRfi($rfi, $organizationId, (int) $request->user()?->id));
    }

    public function uploadRfiAttachment(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg'],
            ]);
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return CustomerResponse::success(new ChangeRfiResource($this->service->attachRfiFile(
                $this->service->findVisibleRfi($organizationId, $id, (int) $request->user()?->id, true),
                $organizationId,
                (int) $request->user()?->id,
                $validated['file'],
            )));
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 403);
        } catch (HttpExceptionInterface $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.attach');
        }
    }

    public function downloadRfiAttachment(Request $request, int $id, string $attachmentId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $userId = (int) $request->user()?->id;
            $url = $this->service->rfiAttachmentUrl($this->service->findVisibleRfi($organizationId, $id, $userId, true), $organizationId, $userId, $attachmentId);
            if ($url === null) {
                return CustomerResponse::error(trans_message('change_management.errors.rfi_attachment_unavailable'), 503);
            }

            return CustomerResponse::success(['url' => $url, 'expires_in' => 300]);
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.attachment.download');
        }
    }

    private function rfiAction(Request $request, int $id, callable $callback): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return CustomerResponse::success(new ChangeRfiResource($callback(
                $this->service->findVisibleRfi($organizationId, $id, (int) $request->user()?->id, true), $organizationId,
            )));
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 403);
        } catch (HttpExceptionInterface $exception) {
            return CustomerResponse::error($exception->getMessage(), $exception->getStatusCode());
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'rfis.action');
        }
    }

    public function changes(Request $request): JsonResponse
    {
        try {
            $paginator = $this->service->paginateChanges(
                (int) $request->attributes->get('current_organization_id'),
                min((int) $request->input('per_page', 20), 100),
                array_merge($request->only(['project_id']), ['status' => 'customer_review'])
            );

            return CustomerResponse::success([
                'items' => ChangeRequestResource::collection($paginator->items())->resolve(),
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.index');
        }
    }

    public function approve(ApproveChangeRequest $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validated();
            $change = $this->service->findChange((int) $request->attributes->get('current_organization_id'), $id);

            return CustomerResponse::success(
                new ChangeRequestResource($this->service->customerApprove(
                    $change,
                    (int) $request->user()?->id,
                    $validated['approved_cost_amount'],
                    $validated['comment'] ?? null
                )),
                trans_message('change_management.messages.customer_approved')
            );
        } catch (ValidationException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422, $exception->errors());
        } catch (DomainException $exception) {
            return CustomerResponse::error($exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            return $this->failed($request, $exception, 'changes.approve');
        }
    }

    private function failed(Request $request, \Throwable $exception, string $action): JsonResponse
    {
        Log::error('change_management.customer_failed', [
            'action' => $action,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'user_id' => $request->user()?->id,
            'error' => $exception->getMessage(),
        ]);

        return CustomerResponse::error(trans_message('change_management.errors.unexpected'), 500);
    }
}
