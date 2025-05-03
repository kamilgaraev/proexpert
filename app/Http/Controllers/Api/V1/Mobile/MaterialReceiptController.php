<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\MaterialReceipt\StoreMaterialReceiptRequest;
use App\Http\Resources\MaterialReceiptResource;
use App\Http\Resources\MaterialReceiptCollection;
use App\Services\Materials\MaterialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaterialReceiptController extends Controller
{
    protected MaterialService $materialService;

    public function __construct(MaterialService $materialService)
    {
        $this->materialService = $materialService;
    }

    public function index(Request $request): JsonResponse
    {
        $receipts = $this->materialService->getMaterialReceiptsForForeman(
            auth()->id(),
            $request->get('project_id'),
            $request->get('per_page', 15),
            $request->get('date_from'),
            $request->get('date_to'),
            $request->get('material_id'),
            $request->get('supplier_id'),
            $request->get('sync_status')
        );

        return response()->json(new MaterialReceiptCollection($receipts));
    }

    public function store(StoreMaterialReceiptRequest $request): JsonResponse
    {
        $receipt = $this->materialService->createMaterialReceipt(
            $request->validated(),
            auth()->id()
        );

        return response()->json(new MaterialReceiptResource($receipt), 201);
    }

    public function show(int $id): JsonResponse
    {
        $receipt = $this->materialService->getMaterialReceiptByIdForForeman($id, auth()->id());

        return response()->json(new MaterialReceiptResource($receipt));
    }

    public function uploadPhoto(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'photo' => 'required|file|image|max:10240',
        ]);

        $result = $this->materialService->addPhotoToMaterialReceipt(
            $id,
            $request->file('photo'),
            auth()->id()
        );

        return response()->json($result);
    }

    public function getOfflineData(): JsonResponse
    {
        $data = $this->materialService->getOfflineDataForMaterialReceipts(auth()->id());

        return response()->json($data);
    }

    public function syncOfflineData(Request $request): JsonResponse
    {
        $request->validate([
            'receipts' => 'required|array',
            'receipts.*.project_id' => 'required|integer',
            'receipts.*.material_id' => 'required|integer',
            'receipts.*.supplier_id' => 'required|integer',
            'receipts.*.quantity' => 'required|numeric|min:0.01',
            'receipts.*.receipt_date' => 'required|date',
            'receipts.*.document_number' => 'nullable|string|max:255',
            'receipts.*.notes' => 'nullable|string|max:1000',
            'receipts.*.offline_id' => 'required|string',
            'receipts.*.photos' => 'nullable|array',
            'receipts.*.photos.*' => 'nullable|string',
        ]);

        $result = $this->materialService->syncOfflineMaterialReceipts(
            $request->input('receipts'),
            auth()->id()
        );

        return response()->json($result);
    }
} 