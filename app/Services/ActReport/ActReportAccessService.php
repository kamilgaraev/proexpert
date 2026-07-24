<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Exceptions\BusinessLogicException;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\User;
use App\Services\Contract\ContractAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

use function trans_message;

class ActReportAccessService
{
    public const PERMISSION_VIEW = 'act_reports.view';
    public const PERMISSION_CREATE = 'act_reports.create';
    public const PERMISSION_EDIT = 'act_reports.edit';
    public const PERMISSION_MANAGE_WORKS = 'act_reports.works.update';
    public const PERMISSION_CONTRACTS_VIEW = 'act_reports.contracts.view';
    public const PERMISSION_EXPORT_PDF = 'act_reports.export.pdf';
    public const PERMISSION_EXPORT_EXCEL = 'act_reports.export.excel';
    public const PERMISSION_BULK_EXPORT_EXCEL = 'act_reports.bulk_export.excel';
    public const PERMISSION_DOWNLOAD_PDF = 'act_reports.download_pdf';

    public function __construct(
        private readonly ContractAccessService $contractAccessService
    ) {
    }

    public function currentOrganizationId(Request $request): int
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id
            ?? $request->user()?->organization_id
            ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new BusinessLogicException(trans_message('act_reports.organization_not_found'), 400);
        }

        return (int) $organizationId;
    }

    public function authorize(Request $request, string $permission, int $organizationId): void
    {
        $user = $request->user();

        if (!$user instanceof User || !$user->can($permission, ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
        }
    }

    public function authorizeAct(Request $request, ContractPerformanceAct $act): void
    {
        $organizationId = $this->currentOrganizationId($request);
        $act->loadMissing('contract.contractor', 'contract.organization');

        if (!$act->contract || !$this->contractAccessService->canAccess($act->contract, $organizationId)) {
            throw new BusinessLogicException(trans_message('act_reports.access_denied'), 403);
        }
    }

    public function resolveAccessibleAct(Request $request, mixed $act): ContractPerformanceAct
    {
        $resolvedAct = $this->resolveAct($act);
        $this->authorizeAct($request, $resolvedAct);

        return $resolvedAct;
    }

    public function resolveAct(mixed $act): ContractPerformanceAct
    {
        if ($act instanceof ContractPerformanceAct) {
            return $act;
        }

        $resolvedAct = ContractPerformanceAct::query()->find((int) $act);

        if (!$resolvedAct) {
            throw new BusinessLogicException(trans_message('act_reports.act_not_found'), 404);
        }

        return $resolvedAct;
    }

    public function resolveActFile(ContractPerformanceAct $act, mixed $file): File
    {
        $act->loadMissing('contract');

        $resolvedFile = $act->files()
            ->where('organization_id', (int) $act->contract->organization_id)
            ->find((int) $file);

        if (!$resolvedFile) {
            throw new BusinessLogicException(trans_message('act_reports.file_not_found'), 404);
        }

        return $resolvedFile;
    }

    public function findAccessibleContractOrFail(int $organizationId, int $contractId): Contract
    {
        $contract = $this->contractAccessService->findAccessible($contractId, $organizationId);

        if (!$contract) {
            throw new BusinessLogicException(trans_message('act_reports.contract_not_found'), 404);
        }

        return $contract;
    }

    public function accessibleContractsQuery(int $organizationId): Builder
    {
        $query = Contract::query();
        $this->contractAccessService->applyAccessibleScope($query, $organizationId);

        return $query;
    }
}
