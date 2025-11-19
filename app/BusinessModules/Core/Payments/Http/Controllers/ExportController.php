<?php

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ExportController extends Controller
{
    public function __construct(
        private readonly PaymentExportService $exportService
    ) {}
    
    /**
     * Экспорт списка документов в Excel
     * 
     * POST /api/v1/admin/payments/export/excel
     */
    public function excel(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'filters' => 'sometimes|array',
            'filters.status' => 'sometimes|string',
            'filters.document_type' => 'sometimes|string',
            'filters.date_from' => 'sometimes|date',
            'filters.date_to' => 'sometimes|date',
            'filters.contractor_id' => 'sometimes|integer',
            'filters.project_id' => 'sometimes|integer',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $filters = $request->input('filters', []);
            
            // Получаем документы с фильтрами
            $query = PaymentDocument::where('organization_id', $organizationId);
            
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            if (isset($filters['document_type'])) {
                $query->where('document_type', $filters['document_type']);
            }
            
            if (isset($filters['contractor_id'])) {
                $query->where('contractor_id', $filters['contractor_id']);
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
            
            $documents = $query->with(['contractor', 'project'])->get();
            
            $filePath = $this->exportService->exportDocumentsToExcel($documents, 'Платежные документы');
            
            return response()->download(storage_path('app/' . $filePath), basename($filePath), [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            Log::error('payments.export.excel.error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось экспортировать данные',
            ], 500);
        }
    }
    
    /**
     * Экспорт платежного поручения в PDF
     * 
     * POST /api/v1/admin/payments/export/pdf/{documentId}
     */
    public function pdf(Request $request, int $documentId): Response
    {
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            
            $document = PaymentDocument::where('organization_id', $organizationId)
                ->with(['contractor', 'project', 'counterpartyOrganization'])
                ->findOrFail($documentId);
            
            $filePath = $this->exportService->exportPaymentOrderToPdf($document);
            
            return response()->download(storage_path('app/' . $filePath), basename($filePath), [
                'Content-Type' => 'application/pdf',
            ])->deleteFileAfterSend(true);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Документ не найден',
            ], 404);
        } catch (\Exception $e) {
            Log::error('payments.export.pdf.error', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось экспортировать PDF',
            ], 500);
        }
    }
    
    /**
     * Экспорт реестра в формат 1С
     * 
     * POST /api/v1/admin/payments/export/1c
     */
    public function onec(Request $request): Response
    {
        $validator = Validator::make($request->all(), [
            'document_ids' => 'required|array|min:1',
            'document_ids.*' => 'required|integer|exists:payment_documents,id',
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }
        
        try {
            $organizationId = $request->attributes->get('current_organization_id');
            $documentIds = $request->input('document_ids');
            
            $documents = PaymentDocument::where('organization_id', $organizationId)
                ->whereIn('id', $documentIds)
                ->with(['contractor', 'project', 'counterpartyOrganization'])
                ->get();
            
            if ($documents->count() !== count($documentIds)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Некоторые документы не найдены',
                ], 404);
            }
            
            $filePath = $this->exportService->exportPaymentRegistry1C($documents);
            
            return response()->download(storage_path('app/' . $filePath), basename($filePath), [
                'Content-Type' => 'text/plain',
            ])->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            Log::error('payments.export.1c.error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Не удалось экспортировать реестр',
            ], 500);
        }
    }
}

