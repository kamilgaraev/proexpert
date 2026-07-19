<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

use function trans_message;

final class LegalDocumentGovernanceService
{
    public function __construct(private readonly AuthorizationService $authorization) {}

    /** @param array<string, mixed> $retention */
    public function updateRetention(LegalArchiveDocument $document, User $actor, array $retention): LegalArchiveDocument
    {
        $this->assertAllowed($document, $actor, 'legal_archive.retention.manage');

        return DB::transaction(function () use ($document, $actor, $retention): LegalArchiveDocument {
            $document->forceFill(Arr::only($retention, [
                'retention_policy',
                'retention_basis',
                'retention_started_at',
                'retention_until',
            ]));
            $document->forceFill(['updated_by_user_id' => $actor->id])->save();

            return $document->refresh();
        });
    }

    public function setLegalHold(LegalArchiveDocument $document, User $actor, bool $enabled): LegalArchiveDocument
    {
        $this->assertAllowed($document, $actor, 'legal_archive.legal_hold.manage');

        return DB::transaction(function () use ($document, $actor, $enabled): LegalArchiveDocument {
            $document->forceFill([
                'legal_hold' => $enabled,
                'updated_by_user_id' => $actor->id,
            ])->save();

            return $document->refresh();
        });
    }

    private function assertAllowed(LegalArchiveDocument $document, User $actor, string $permission): void
    {
        $organizationId = (int) $document->organization_id;

        if (
            $organizationId < 1
            || (int) $actor->current_organization_id !== $organizationId
            || ! $this->authorization->can($actor, $permission, ['organization_id' => $organizationId])
        ) {
            throw new AuthorizationException(trans_message('legal_archive.messages.governance_access_denied'));
        }
    }
}
