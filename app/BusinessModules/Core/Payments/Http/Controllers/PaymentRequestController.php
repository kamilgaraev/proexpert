<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentRequestService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

use function trans_message;

class PaymentRequestController extends Controller
{
    public function __construct(
        private readonly PaymentRequestService $requestService
    ) {}

    public function incoming(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $filters = $this->validateListFilters($request, $organizationId);
            $requests = $this->requestService->getIncomingRequests($organizationId, $filters);

            return $this->buildRequestsResponse($requests, trans_message('payments.requests.loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('payment_request.incoming.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.load_error'), 500);
        }
    }

    public function outgoing(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $filters = $this->validateListFilters($request, $organizationId);
            $requests = $this->requestService->getOutgoingRequests($organizationId, $filters);

            return $this->buildRequestsResponse($requests, trans_message('payments.requests.loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('payment_request.outgoing.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.load_error'), 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'contractor_id' => [
                    'required',
                    'integer',
                    Rule::exists('contractors', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'project_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('projects', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'contract_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('contracts', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                ],
                'amount' => ['required', 'numeric', 'min:0.01'],
                'currency' => ['nullable', 'string', 'size:3'],
                'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'document_date' => ['nullable', 'date'],
                'due_date' => ['nullable', 'date'],
                'description' => ['nullable', 'string'],
                'payment_purpose' => ['required', 'string', 'max:1000'],
                'bank_account' => ['nullable', 'string', 'size:20'],
                'bank_bik' => ['nullable', 'string', 'size:9'],
                'bank_correspondent_account' => ['nullable', 'string', 'size:20'],
                'bank_name' => ['nullable', 'string'],
                'attached_documents' => ['nullable', 'array'],
                'metadata' => ['nullable', 'array'],
            ]);

            $validated['organization_id'] = $organizationId;
            $validated['created_by_user_id'] = (int) $request->user()->id;
            $document = $this->requestService->createFromContractor($validated);
            $document->loadMissing(['payeeContractor', 'project']);

            return AdminResponse::success(
                $this->formatRequest($document),
                trans_message('payments.requests.created'),
                201
            );
        } catch (\DomainException | \InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('payment_request.store.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.create_error'), 500);
        }
    }

    public function accept(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'scheduled_at' => ['nullable', 'date'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail((int) $id);

            $paymentOrder = $this->requestService->acceptRequest($document, $validated);
            $document->loadMissing(['payeeContractor', 'project']);

            return AdminResponse::success([
                'request' => $this->formatRequest($document),
                'payment_order' => [
                    'id' => $paymentOrder->id,
                    'document_number' => $paymentOrder->document_number,
                    'status' => $paymentOrder->status->value,
                    'status_label' => $paymentOrder->status->label(),
                    'amount' => (float) $paymentOrder->amount,
                    'currency' => $paymentOrder->currency,
                    'created_at' => $paymentOrder->created_at?->toISOString(),
                ],
            ], trans_message('payments.requests.accepted'));
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('payment_request.accept.error', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.accept_error'), 500);
        }
    }

    public function reject(Request $request, int|string $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['required', 'string', 'min:3'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $document = PaymentDocument::query()
                ->forOrganization($organizationId)
                ->findOrFail((int) $id);

            $rejectedDocument = $this->requestService->rejectRequest($document, $validated['reason'], $request->user());
            $rejectedDocument->loadMissing(['payeeContractor', 'project']);

            return AdminResponse::success(
                $this->formatRequest($rejectedDocument),
                trans_message('payments.requests.rejected')
            );
        } catch (\DomainException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.not_found'), 404);
        } catch (\Throwable $e) {
            Log::error('payment_request.reject.error', [
                'request_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.reject_error'), 500);
        }
    }

    public function fromContractor(Request $request, int|string $contractorId): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            validator(
                ['contractor_id' => $contractorId],
                [
                    'contractor_id' => [
                        'required',
                        'integer',
                        Rule::exists('contractors', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
                    ],
                ]
            )->validate();

            $requests = $this->requestService->getRequestsFromContractor($organizationId, (int) $contractorId);

            return $this->buildRequestsResponse($requests, trans_message('payments.requests.loaded'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('payment_request.from_contractor.error', [
                'contractor_id' => $contractorId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.load_error'), 500);
        }
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $stats = $this->requestService->getStatistics($organizationId);

            return AdminResponse::success($stats, trans_message('payments.requests.loaded'));
        } catch (\Throwable $e) {
            Log::error('payment_request.statistics.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.requests.statistics_error'), 500);
        }
    }

    private function validateListFilters(Request $request, int $organizationId): array
    {
        return $request->validate([
            'status' => ['nullable', 'string'],
            'project_id' => [
                'nullable',
                'integer',
                Rule::exists('projects', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'contractor_id' => [
                'nullable',
                'integer',
                Rule::exists('contractors', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId)),
            ],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
    }

    private function buildRequestsResponse(Collection $requests, string $message): JsonResponse
    {
        if ($requests instanceof EloquentCollection) {
            $requests->loadMissing(['payeeContractor', 'project']);
        }

        $items = $requests->map(fn (PaymentDocument $document) => $this->formatRequest($document))->values();

        return AdminResponse::paginated(
            $items,
            [
                'current_page' => 1,
                'per_page' => $items->count(),
                'total' => $items->count(),
                'last_page' => 1,
            ],
            $message,
            200,
            [
                'total_amount' => (float) $requests->sum('amount'),
            ]
        );
    }

    private function formatRequest(PaymentDocument $document): array
    {
        $contractId = $document->source_type === Contract::class ? (int) $document->source_id : null;
        $hasBankDetails = $document->bank_name || $document->bank_bik || $document->bank_account;

        return [
            'id' => $document->id,
            'document_number' => $document->document_number,
            'document_date' => $document->document_date?->format('Y-m-d'),
            'due_date' => $document->due_date?->format('Y-m-d'),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'amount' => (float) $document->amount,
            'currency' => $document->currency,
            'total_amount' => (float) $document->amount,
            'payment_purpose' => $document->payment_purpose,
            'description' => $document->description,
            'notes' => $document->notes,
            'project_id' => $document->project_id,
            'contract_id' => $contractId,
            'source_type' => $document->source_type,
            'source_id' => $document->source_id,
            'created_at' => $document->created_at?->toISOString(),
            'updated_at' => $document->updated_at?->toISOString(),
            'bank_details' => $hasBankDetails ? [
                'bank_name' => $document->bank_name,
                'bank_bik' => $document->bank_bik,
                'bank_account' => $document->bank_account,
                'bank_correspondent_account' => $document->bank_correspondent_account,
            ] : null,
            'contractor' => [
                'id' => $document->payee_contractor_id,
                'name' => $document->getPayeeName(),
            ],
            'project' => $document->project ? [
                'id' => $document->project->id,
                'name' => $document->project->name,
            ] : null,
        ];
    }
}
