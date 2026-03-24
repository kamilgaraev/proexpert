<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\AccountingIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AccountingIntegrationController extends Controller
{
    public function __construct(
        protected AccountingIntegrationService $integrationService
    ) {
    }

    public function importUsers(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('accounting_integration.organization_required'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->respondWithIntegrationResult(
            $this->integrationService->importUsers($organizationId),
            trans_message('accounting_integration.users_import_completed'),
            'users',
            $organizationId
        );
    }

    public function importProjects(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('accounting_integration.organization_required'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->respondWithIntegrationResult(
            $this->integrationService->importProjects($organizationId),
            trans_message('accounting_integration.projects_import_completed'),
            'projects',
            $organizationId
        );
    }

    public function importMaterials(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('accounting_integration.organization_required'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->respondWithIntegrationResult(
            $this->integrationService->importMaterials($organizationId),
            trans_message('accounting_integration.materials_import_completed'),
            'materials',
            $organizationId
        );
    }

    public function exportTransactions(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('accounting_integration.organization_required'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return $this->respondWithIntegrationResult(
            $this->integrationService->exportTransactions(
                $organizationId,
                $request->input('start_date'),
                $request->input('end_date')
            ),
            trans_message('accounting_integration.transactions_export_completed'),
            'transactions',
            $organizationId
        );
    }

    public function getSyncStatus(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId();

        if ($organizationId === null) {
            return AdminResponse::error(
                trans_message('accounting_integration.organization_required'),
                Response::HTTP_BAD_REQUEST
            );
        }

        return AdminResponse::success(
            [
                'last_sync' => [
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                    'status' => 'completed',
                    'users_synced' => true,
                    'projects_synced' => true,
                    'materials_synced' => true,
                    'transactions_synced' => true,
                ],
            ],
            trans_message('accounting_integration.status_ok')
        );
    }

    private function respondWithIntegrationResult(
        array $result,
        string $successMessage,
        string $scope,
        int $organizationId
    ): JsonResponse {
        if (($result['success'] ?? false) === true) {
            $data = $result;
            unset($data['success'], $data['message']);

            return AdminResponse::success(
                $data === [] ? null : $data,
                $successMessage
            );
        }

        Log::error('Accounting integration request failed', [
            'scope' => $scope,
            'organization_id' => $organizationId,
            'message' => $result['message'] ?? null,
        ]);

        return AdminResponse::error(
            trans_message('accounting_integration.integration_error'),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            isset($result['message']) ? ['details' => $result['message']] : null
        );
    }

    private function resolveOrganizationId(): ?int
    {
        $organizationId = Auth::user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }
}
