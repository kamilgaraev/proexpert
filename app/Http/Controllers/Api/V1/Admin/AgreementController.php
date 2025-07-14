<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contract\SupplementaryAgreementService;
use App\Http\Requests\Api\V1\Admin\Agreement\StoreSupplementaryAgreementRequest;
use App\Http\Requests\Api\V1\Admin\Agreement\UpdateSupplementaryAgreementRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AgreementController extends Controller
{
    public function __construct(private SupplementaryAgreementService $service) {}

    public function index(Request $request, int $contractId)
    {
        $perPage = $request->query('per_page', 15);
        return $this->service->paginateByContract($contractId, $perPage);
    }

    public function store(StoreSupplementaryAgreementRequest $request)
    {
        $agreement = $this->service->create($request->toDto());
        return response()->json($agreement, Response::HTTP_CREATED);
    }

    public function show(int $id)
    {
        $agreement = $this->service->getById($id);
        if (!$agreement) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        return $agreement;
    }

    public function update(UpdateSupplementaryAgreementRequest $request, int $id)
    {
        $agreement = $this->service->getById($id);
        if (!$agreement) {
            return response()->json(['message' => 'Not found'], Response::HTTP_NOT_FOUND);
        }
        $dto = $request->toDto($agreement->contract_id);
        $this->service->update($id, $dto);
        return response()->json($this->service->getById($id));
    }

    public function destroy(int $id)
    {
        $this->service->delete($id);
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
} 