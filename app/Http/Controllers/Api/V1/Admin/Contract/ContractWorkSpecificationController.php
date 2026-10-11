<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\Specification\ExportWorkSpecificationRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractWorkSpecificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContractWorkSpecificationController extends Controller
{
    public function __construct(private readonly ContractWorkSpecificationService $service) {}

    public function show(Request $request, int $project, int $contract): JsonResponse
    {
        return AdminResponse::success($this->service->current($request->user(), $project, $contract));
    }

    public function export(ExportWorkSpecificationRequest $request, int $project, int $contract): StreamedResponse
    {
        $format = $request->validated('format', 'pdf');
        $bytes = $this->service->export($request->user(), $project, $contract, $format);

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, 'specification-'.$contract.'.'.$format, ['Content-Type' => $format === 'pdf'
            ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    }
}
