<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentExportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function trans_message;

class ExportController extends Controller
{
    public function __construct(
        private readonly PaymentExportService $exportService
    ) {}

    public function excel(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'filters' => ['sometimes', 'array'],
                'filters.status' => ['sometimes', 'string'],
                'filters.document_type' => ['sometimes', 'string'],
                'filters.date_from' => ['sometimes', 'date'],
                'filters.date_to' => ['sometimes', 'date'],
                'filters.contractor_id' => [
                    'sometimes',
                    'integer',
                    Rule::exists('contractors', 'id')->where('organization_id', $organizationId),
                ],
                'filters.project_id' => [
                    'sometimes',
                    'integer',
                    Rule::exists('projects', 'id')->where('organization_id', $organizationId),
                ],
            ]);
            $filters = $validated['filters'] ?? [];
            $query = PaymentDocument::query()->where('organization_id', $organizationId);

            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['document_type'])) {
                $query->where('document_type', $filters['document_type']);
            }

            if (isset($filters['contractor_id'])) {
                $query->where(function ($builder) use ($filters) {
                    $builder->where('contractor_id', $filters['contractor_id'])
                        ->orWhere('payer_contractor_id', $filters['contractor_id'])
                        ->orWhere('payee_contractor_id', $filters['contractor_id']);
                });
            }

            if (isset($filters['project_id'])) {
                $query->where('project_id', $filters['project_id']);
            }

            if (isset($filters['date_from'])) {
                $query->where('document_date', '>=', $filters['date_from']);
            }

            if (isset($filters['date_to'])) {
                $query->where('document_date', '<=', $filters['date_to']);
            }

            $documents = $query
                ->with(['payerContractor', 'payeeContractor', 'payerOrganization', 'payeeOrganization', 'project'])
                ->get();

            $filePath = $this->exportService->exportDocumentsToExcel($documents, 'Платежные документы');

            return response()->download($filePath, basename($filePath), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.export.excel.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('payments.export.excel_error'), 500);
        }
    }

    public function pdf(Request $request, int|string $documentId): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            $document = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->with(['payerContractor', 'payeeContractor', 'payerOrganization', 'payeeOrganization', 'project'])
                ->findOrFail($documentId);

            $filePath = $this->exportService->exportPaymentOrderToPdf($document);

            return response()->download($filePath, basename($filePath), [
                'Content-Type' => 'application/pdf',
            ])->deleteFileAfterSend(true);
        } catch (ModelNotFoundException $e) {
            return AdminResponse::error(trans_message('payments.export.pdf_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('payments.export.pdf.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.export.pdf_error'), 500);
        }
    }

    public function onec(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $validated = $request->validate([
                'document_ids' => ['required', 'array', 'min:1'],
                'document_ids.*' => [
                    'required',
                    'integer',
                    Rule::exists('payment_documents', 'id')->where('organization_id', $organizationId),
                ],
            ]);
            $documentIds = $validated['document_ids'];

            $documents = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->whereIn('id', $documentIds)
                ->with(['payerContractor', 'payeeContractor', 'payerOrganization', 'payeeOrganization', 'project'])
                ->get();

            if ($documents->count() !== count($documentIds)) {
                return AdminResponse::error(trans_message('payments.export.onec_not_found'), 404);
            }

            $filePath = $this->exportService->exportPaymentRegistry1C($documents);

            return response()->download($filePath, basename($filePath), [
                'Content-Type' => 'text/plain',
            ])->deleteFileAfterSend(true);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.export.1c.error', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.export.onec_error'), 500);
        }
    }
}
