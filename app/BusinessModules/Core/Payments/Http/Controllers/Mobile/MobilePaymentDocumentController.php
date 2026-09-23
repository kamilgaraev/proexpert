<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers\Mobile;

use App\BusinessModules\Core\Payments\Exceptions\PaymentDocumentDeviationBlockedException;
use App\BusinessModules\Core\Payments\Http\Requests\MobilePaymentDocumentIndexRequest;
use App\BusinessModules\Core\Payments\Http\Requests\MobileApprovePaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\MobileRejectPaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\MobileRegisterPaymentDocumentPaymentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\MobileStorePaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\MobileSubmitPaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\MobileUpdatePaymentDocumentRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobilePaymentDocumentService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MobilePaymentDocumentController extends Controller
{
    public function __construct(private readonly MobilePaymentDocumentService $payments) {}

    public function index(MobilePaymentDocumentIndexRequest $request): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->index(
                $this->organizationId($request),
                $this->actor($request),
                $request->validated()
            ));
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'index');
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->show(
                $this->organizationId($request),
                $this->actor($request),
                $id
            ));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('payments.not_found'), 404);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'show', ['document_id' => $id]);
        }
    }

    public function store(MobileStorePaymentDocumentRequest $request): JsonResponse
    {
        try {
            $result = $this->payments->create(
                $this->organizationId($request),
                $this->actor($request),
                $request->validated()
            );

            return MobileResponse::success($result['data'], null, $result['created'] ? 201 : 200);
        } catch (PaymentDocumentDeviationBlockedException $exception) {
            return MobileResponse::error($exception->getMessage(), 422, [
                'requires_justification' => true,
                'deviation_data' => $exception->deviationData(),
            ]);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('payments.validation_error'), 422, $exception->errors());
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'store');
        }
    }

    public function update(MobileUpdatePaymentDocumentRequest $request, int $id): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->update(
                $this->organizationId($request),
                $this->actor($request),
                $id,
                $request->validated()
            ));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('payments.not_found'), 404);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'update', ['document_id' => $id]);
        }
    }

    public function submit(MobileSubmitPaymentDocumentRequest $request, int $id): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->submit(
                $this->organizationId($request),
                $this->actor($request),
                $id,
                $request->validated('budget_override_reason')
            ));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('payments.not_found'), 404);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'submit', ['document_id' => $id]);
        }
    }

    public function registerPayment(MobileRegisterPaymentDocumentPaymentRequest $request, int $id): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->registerPayment(
                $this->organizationId($request),
                $this->actor($request),
                $id,
                $request->validated()
            ));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $exception) {
            return MobileResponse::error(trans_message('payments.validation_error'), 422, $exception->errors());
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'register_payment', ['document_id' => $id]);
        }
    }

    public function approve(MobileApprovePaymentDocumentRequest $request, int $id): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->approve(
                $this->organizationId($request),
                $this->actor($request),
                $id,
                $request->validated()
            ));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('payments.not_found'), 404);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'approve', ['document_id' => $id]);
        }
    }

    public function reject(MobileRejectPaymentDocumentRequest $request, int $id): JsonResponse
    {
        try {
            return MobileResponse::success($this->payments->reject(
                $this->organizationId($request),
                $this->actor($request),
                $id,
                $request->validated()
            ));
        } catch (ModelNotFoundException) {
            return MobileResponse::error(trans_message('payments.not_found'), 404);
        } catch (\DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->failed($request, $exception, 'reject', ['document_id' => $id]);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
            ?? $request->user()?->organization_id
            ?? 0);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new \DomainException(trans_message('auth.mobile_access_denied'), 403);
        }

        return $user;
    }

    private function domainError(\DomainException $exception): JsonResponse
    {
        $code = $exception->getCode();

        return MobileResponse::error($exception->getMessage(), in_array($code, [403, 404, 409, 422], true) ? $code : 422);
    }

    private function failed(Request $request, Throwable $exception, string $action, array $context = []): JsonResponse
    {
        Log::error('mobile.payment_document.'.$action.'.error', $context + [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]);

        return MobileResponse::error(trans_message('payments.documents.load_error'), 500);
    }
}
