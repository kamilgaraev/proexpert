<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Services\Import\BankStatementImportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

class BankStatementImportController extends Controller
{
    public function __construct(
        private readonly BankStatementImportService $importService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'file' => ['required', 'file', 'mimes:txt,csv', 'max:5120'],
            ]);

            $organizationId = (int) $request->attributes->get('current_organization_id');
            $fileContent = (string) file_get_contents($validated['file']->getRealPath());

            $result = $this->importService->import($organizationId, $fileContent, (int) $request->user()->id);

            return AdminResponse::success($result, trans_message('payments.import.completed'));
        } catch (\Illuminate\Validation\ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('payments.import.bank_statement.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.import.error'), 500);
        }
    }
}
