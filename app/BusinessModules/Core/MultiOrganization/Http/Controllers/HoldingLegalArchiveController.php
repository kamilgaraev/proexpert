<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Services\ContextAwareOrganizationScope;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Landing\HoldingLegalArchiveDossierResource;
use App\Http\Responses\LandingResponse;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function trans_message;

final class HoldingLegalArchiveController extends Controller
{
    public function __construct(
        private readonly ContextAwareOrganizationScope $scope,
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalDocumentDownloadService $downloads,
        private readonly AuthorizationService $authorization,
    ) {}

    public function show(Request $request, int $contractId): JsonResponse
    {
        try {
            $holdingId = $this->holdingId($request);
            $actor = $this->actor($request);
            $contract = Contract::query()
                ->whereKey($contractId)
                ->whereIn('organization_id', $this->scope->getOrganizationScope($holdingId))
                ->first();
            if (! $contract instanceof Contract) {
                return $this->notFound();
            }

            $document = $this->documentForContract($actor, $contract);
            if (! $document instanceof LegalArchiveDocument) {
                return $this->notFound();
            }

            $canDownload = $this->canDownload($actor, $document);
            $document->setAttribute('holding_can_download', $canDownload);
            $document->setAttribute('holding_contract', [
                'id' => (int) $contract->id,
                'number' => (string) $contract->number,
                'status' => (string) $contract->status,
            ]);
            if ($this->authorization->can($actor, 'multi-organization.reports.financial', ['organization_id' => $holdingId])) {
                $totalAmount = (float) $contract->total_amount;
                $paidAmount = (float) $contract->payments()->sum('paid_amount');
                $document->setAttribute('holding_financial_summary', [
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'remaining_amount' => $totalAmount - $paidAmount,
                ]);
            }

            return LandingResponse::success(
                new HoldingLegalArchiveDossierResource($document),
                trans_message('legal_archive.messages.document_loaded'),
            );
        } catch (Throwable $error) {
            Log::error('[HoldingLegalArchiveController.show] Unexpected error', [
                'contract_id' => $contractId,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $error::class,
            ]);

            return LandingResponse::error(trans_message('holding.contract_load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function preview(Request $request, int $contractId, int $versionId): JsonResponse
    {
        return $this->fileUrl($request, $contractId, $versionId, 'preview');
    }

    public function download(Request $request, int $contractId, int $versionId): JsonResponse
    {
        return $this->fileUrl($request, $contractId, $versionId, 'download');
    }

    private function fileUrl(Request $request, int $contractId, int $versionId, string $purpose): JsonResponse
    {
        try {
            $holdingId = $this->holdingId($request);
            $actor = $this->actor($request);
            $contract = Contract::query()->whereKey($contractId)
                ->whereIn('organization_id', $this->scope->getOrganizationScope($holdingId))->first();
            if (! $contract instanceof Contract) {
                return $this->notFound();
            }
            $document = $this->documentForContract($actor, $contract);
            $version = $document?->versions()->whereKey($versionId)->with('documentFile.document')->first();
            if (! $version instanceof LegalArchiveDocumentVersion) {
                return $this->notFound();
            }
            $this->access->authorize($actor, $document, 'download');

            return LandingResponse::success([
                'url' => $this->downloads->temporaryUrl($version, $actor, $purpose),
                'expires_in_seconds' => 300,
            ], trans_message('legal_archive.messages.file_url_created'));
        } catch (AuthorizationException) {
            return $this->notFound();
        } catch (Throwable $error) {
            Log::error('[HoldingLegalArchiveController.fileUrl] Unexpected error', [
                'contract_id' => $contractId,
                'version_id' => $versionId,
                'purpose' => $purpose,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'error' => $error::class,
            ]);

            return LandingResponse::error(trans_message('holding.contract_load_error'), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function documentForContract(User $actor, Contract $contract): ?LegalArchiveDocument
    {
        $documents = LegalArchiveDocument::query()
            ->where('organization_id', (int) $contract->organization_id)
            ->whereHas('links', static function ($query) use ($contract): void {
                $query->whereIn('linked_type', ['contract', Contract::class])
                    ->where('linked_id', (int) $contract->id);
            })
            ->with(['organization:id,name', 'files.currentVersion', 'signatures'])
            ->orderByDesc('id')
            ->get();

        foreach ($documents as $document) {
            try {
                $this->access->authorize($actor, $document, 'view');

                return $document;
            } catch (AuthorizationException) {
            }
        }

        return null;
    }

    private function canDownload(User $actor, LegalArchiveDocument $document): bool
    {
        try {
            $this->access->authorize($actor, $document, 'download');

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    private function holdingId(Request $request): int
    {
        $holdingId = (int) $request->attributes->get('current_organization_id');
        $organization = Organization::query()->findOrFail($holdingId);
        if (! $organization->is_holding) {
            throw new AuthorizationException;
        }

        return $holdingId;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function notFound(): JsonResponse
    {
        return LandingResponse::error(trans_message('legal_archive.messages.document_not_found'), Response::HTTP_NOT_FOUND);
    }
}
