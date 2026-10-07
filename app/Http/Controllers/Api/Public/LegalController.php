<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\JsonResponse;

final class LegalController extends Controller
{
    public function __invoke(LegalDocumentService $documents): JsonResponse
    {
        return LandingResponse::success($documents->manifest())->header('Cache-Control', 'no-store');
    }
}
