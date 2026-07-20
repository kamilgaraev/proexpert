<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Sources;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\SupplementaryAgreement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class LegalDocumentSourceResolver
{
    public function assertOwnedSource(int $organizationId, mixed $sourceType, mixed $sourceId): ?Model
    {
        if (($sourceType === null || $sourceType === '') && ($sourceId === null || $sourceId === '')) {
            return null;
        }
        $type = is_string($sourceType) ? LegalDocumentSourceType::tryFrom($sourceType) : null;
        if ($type === null || $organizationId < 1) {
            $this->invalid();
        }
        $id = $type === LegalDocumentSourceType::CRM_DEAL
            ? trim((string) $sourceId)
            : filter_var($sourceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === '' || $id === false) {
            $this->invalid();
        }

        $source = match ($type) {
            LegalDocumentSourceType::PROJECT => Project::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
            LegalDocumentSourceType::CONTRACT => Contract::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
            LegalDocumentSourceType::SUPPLEMENTARY_AGREEMENT => SupplementaryAgreement::query()
                ->whereKey($id)->whereHas('contract', static fn ($query) => $query->where('organization_id', $organizationId))->first(),
            LegalDocumentSourceType::PERFORMANCE_ACT => ContractPerformanceAct::query()
                ->whereKey($id)->whereHas('contract', static fn ($query) => $query->where('organization_id', $organizationId))->first(),
            LegalDocumentSourceType::PURCHASE_ORDER => PurchaseOrder::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
            LegalDocumentSourceType::PAYMENT_DOCUMENT => PaymentDocument::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
            LegalDocumentSourceType::COMMERCIAL_PROPOSAL => CommercialProposal::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
            LegalDocumentSourceType::CRM_DEAL => CrmDeal::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
            LegalDocumentSourceType::ESTIMATE => Estimate::query()
                ->whereKey($id)->where('organization_id', $organizationId)->first(),
        };
        if (! $source instanceof Model) {
            $this->invalid();
        }

        return $source;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages([
            'source_id' => [trans_message('legal_archive.messages.source_not_available')],
        ]);
    }
}
