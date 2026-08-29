<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Exceptions\PaymentDocumentDeviationBlockedException;
use App\BusinessModules\Core\Payments\Http\Requests\BulkActionRequest;
use App\BusinessModules\Core\Payments\Http\Requests\CancelPaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\ContractPaymentAvailabilityRequest;
use App\BusinessModules\Core\Payments\Http\Requests\GeneratePaymentPurposeRequest;
use App\BusinessModules\Core\Payments\Http\Requests\PaymentDocumentIndexRequest;
use App\BusinessModules\Core\Payments\Http\Requests\PreviewPaymentDocumentBudgetRequest;
use App\BusinessModules\Core\Payments\Http\Requests\RegisterPaymentDocumentPaymentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\SchedulePaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\StorePaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\SubmitPaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Http\Requests\UpcomingPaymentDocumentsRequest;
use App\BusinessModules\Core\Payments\Http\Requests\UpdatePaymentDocumentRequest;
use App\BusinessModules\Core\Payments\Services\Export\PaymentOrderPdfService;
use App\BusinessModules\Core\Payments\Services\PaymentBudgetLimitService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentPresenter;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentQueryService;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentWorkflowService;
use App\BusinessModules\Core\Payments\Services\PaymentValidationService;
use App\BusinessModules\Core\Payments\Services\PurchaseOrderContractRequirementService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

final class PaymentDocumentController extends Controller
{
    public function __construct(
        private readonly PaymentDocumentQueryService $queries,
        private readonly PaymentDocumentWorkflowService $workflow,
        private readonly PaymentDocumentPresenter $presenter,
        private readonly PaymentBudgetLimitService $budgetLimits,
        private readonly PaymentOrderPdfService $pdfExport,
        private readonly PurchaseOrderContractRequirementService $contractRequirement,
        private readonly ContractAccessService $contractAccess,
        private readonly PaymentValidationService $validation,
    ) {}

    public function contractAvailability(
        ContractPaymentAvailabilityRequest $request,
        int|string $contract
    ): JsonResponse {
        try {
            $accessibleContract = $this->contractAccess->findAccessibleOrFail(
                (int) $contract,
                $this->organizationId($request),
                $request->projectId()
            );

            return AdminResponse::success(
                $this->validation->contractAvailability($accessibleContract, $request->projectId())
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (Throwable $e) {
            Log::error('payment_document.contract_availability.error', [
                'contract_id' => $contract,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    public function bulkAction(BulkActionRequest $request): JsonResponse
    {
        try {
            $results = $this->workflow->bulkAction(
                $this->organizationId($request),
                $request->validated('ids'),
                (string) $request->validated('action'),
                $request->user(),
                $request->validated()
            );

            return AdminResponse::success($results, trans_message('payments.documents.bulk_processed'));
        } catch (Throwable $e) {
            Log::error('payment_document.bulk_action.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.bulk_error'), 500);
        }
    }

    public function index(PaymentDocumentIndexRequest $request): JsonResponse
    {
        try {
            $documents = $this->queries->listForOrganization($this->organizationId($request), $request->filters());
            $pageItems = $documents->getCollection();
            $this->contractRequirement->preload($pageItems);

            return AdminResponse::paginated(
                $this->presenter->collection($pageItems, $request->user()),
                [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                    'from' => $documents->firstItem(),
                    'to' => $documents->lastItem(),
                ],
                trans_message('payments.documents.loaded'),
                200,
                $this->queries->summary($pageItems),
                [
                    'first' => $documents->url(1),
                    'last' => $documents->url($documents->lastPage()),
                    'prev' => $documents->previousPageUrl(),
                    'next' => $documents->nextPageUrl(),
                ]
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('payment_document.index.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    public function show(Request $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findDetailed($this->organizationId($request), $id);

            return AdminResponse::success($this->presenter->detailed($document, $request->user()));
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (Throwable $e) {
            Log::error('payment_document.show.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    public function store(StorePaymentDocumentRequest $request): JsonResponse
    {
        try {
            $result = $this->workflow->create(
                $this->organizationId($request),
                (int) $request->user()->id,
                $request->validated()
            );

            return AdminResponse::success(
                array_merge($this->presenter->detailed($result['document'], $request->user()), [
                    'warnings' => $result['warnings'],
                ]),
                trans_message('payments.documents.created'),
                201
            );
        } catch (PaymentDocumentDeviationBlockedException $e) {
            return AdminResponse::error($e->getMessage(), 422, [
                'requires_justification' => true,
                'deviation_data' => $e->deviationData(),
            ]);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\DomainException|\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Log::error('payment_document.store.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.create_error'), 500);
        }
    }

    public function update(UpdatePaymentDocumentRequest $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $updated = $this->workflow->update($document, $request->validated());

            return AdminResponse::success(
                $this->presenter->detailed($updated, $request->user()),
                trans_message('payments.documents.updated')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('payment_document.update.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.update_error'), 500);
        }
    }

    public function submit(SubmitPaymentDocumentRequest $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $submitted = $this->workflow->submit(
                $document,
                $request->user(),
                $request->validated('budget_override_reason')
            );

            return AdminResponse::success(
                $this->presenter->detailed($submitted, $request->user()),
                trans_message('payments.documents.submitted')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (Throwable $e) {
            Log::error('payment_document.submit.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.submit_error'), 500);
        }
    }

    public function printOrder(Request $request, int|string $id): Response
    {
        try {
            $document = $this->queries->findForPrintOrder($this->organizationId($request), $id);
            $pdfContent = $this->pdfExport->generate($document);

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="payment_order_'.$document->document_number.'.pdf"',
            ]);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (Throwable $e) {
            Log::error('payment_document.print_order.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.export.pdf_error'), 500);
        }
    }

    public function generatePurpose(GeneratePaymentPurposeRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();

            return AdminResponse::success([
                'purpose' => $this->workflow->generatePurpose(
                    (string) $validated['document_type'],
                    $validated['data']
                ),
            ], trans_message('payments.documents.purpose_generated'));
        } catch (Throwable $e) {
            Log::error('payment_document.generate_purpose.error', [
                'document_type' => $request->input('document_type'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.purpose_error'), 400);
        }
    }

    public function schedule(SchedulePaymentDocumentRequest $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $scheduled = $this->workflow->schedule(
                $document,
                $request->validated('scheduled_at'),
                $request->user(),
                $request->validated('budget_override_reason')
            );

            return AdminResponse::success(
                $this->presenter->detailed($scheduled, $request->user()),
                trans_message('payments.documents.scheduled')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('payment_document.schedule.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.schedule_error'), 500);
        }
    }

    public function registerPayment(RegisterPaymentDocumentPaymentRequest $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $paid = $this->workflow->registerPayment(
                $document,
                (int) $request->user()->id,
                $request->validated()
            );

            return AdminResponse::success(
                $this->presenter->detailed($paid, $request->user()),
                trans_message('payments.documents.registered')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('payment_document.register_payment.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.register_error'), 500);
        }
    }

    public function previewPaymentBudget(PreviewPaymentDocumentBudgetRequest $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $check = $this->budgetLimits->checkPaymentRegistration(
                $document,
                $request->validated('amount'),
                Carbon::parse((string) $request->validated('transaction_date')),
                $request->user()
            );

            return AdminResponse::success($check);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('payment_document.preview_payment_budget.error', [
                'id' => $id,
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('payments.documents.register_error'), 500);
        }
    }

    public function cancel(CancelPaymentDocumentRequest $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $cancelled = $this->workflow->cancel(
                $document,
                (string) $request->validated('reason'),
                $request->user()
            );

            return AdminResponse::success(
                $this->presenter->detailed($cancelled, $request->user()),
                trans_message('payments.documents.cancelled')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (Throwable $e) {
            Log::error('payment_document.cancel.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.cancel_error'), 500);
        }
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        try {
            $document = $this->queries->findForWorkflow($this->organizationId($request), $id);
            $this->workflow->delete($document);

            return AdminResponse::success(null, trans_message('payments.documents.deleted'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (Throwable $e) {
            Log::error('payment_document.destroy.error', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.delete_error'), 500);
        }
    }

    public function overdue(Request $request): JsonResponse
    {
        try {
            $documents = $this->queries->overdue($this->organizationId($request));
            $this->contractRequirement->preload($documents);

            return AdminResponse::paginated(
                $this->presenter->collection($documents, $request->user()),
                [
                    'total' => $documents->count(),
                    'total_amount' => $documents->sum('remaining_amount'),
                ]
            );
        } catch (Throwable $e) {
            Log::error('payment_document.overdue.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    public function upcoming(UpcomingPaymentDocumentsRequest $request): JsonResponse
    {
        try {
            $days = $request->days();
            $documents = $this->queries->upcoming($this->organizationId($request), $days);
            $this->contractRequirement->preload($documents);

            return AdminResponse::paginated(
                $this->presenter->collection($documents, $request->user()),
                [
                    'total' => $documents->count(),
                    'total_amount' => $documents->sum('remaining_amount'),
                    'days' => $days,
                ]
            );
        } catch (Throwable $e) {
            Log::error('payment_document.upcoming.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->queries->statistics($this->organizationId($request)));
        } catch (Throwable $e) {
            Log::error('payment_document.statistics.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.documents.load_error'), 500);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
