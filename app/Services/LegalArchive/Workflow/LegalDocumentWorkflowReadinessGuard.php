<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Profiles\LegalDocumentProfile;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class LegalDocumentWorkflowReadinessGuard
{
    public function __construct(
        private LegalDocumentProfileRegistry $profiles,
        private LegalDocumentProfileValidator $validator,
    ) {}

    public function assertReady(LegalArchiveDocument $document): void
    {
        $profile = $this->profiles->find(
            (int) $document->organization_id,
            $this->profileCode($document),
        );
        $this->validator->validate($profile, (array) $document->structured_fields);
    }

    public function blocker(LegalArchiveDocument $document): ?string
    {
        return $this->blockersFor(collect([$document]))[(int) $document->id] ?? null;
    }

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @return array<int, string>
     */
    public function blockersFor(Collection $documents): array
    {
        $codesByOrganization = [];
        foreach ($documents as $document) {
            $codesByOrganization[(int) $document->organization_id][] = $this->profileCode($document);
        }

        try {
            $profiles = $this->profiles->findManyForOrganizations($codesByOrganization);
        } catch (InvalidArgumentException) {
            return $this->allBlocked($documents);
        }

        $blockers = [];
        foreach ($documents as $document) {
            $profile = $profiles[(int) $document->organization_id][$this->profileCode($document)] ?? null;
            if (! $profile instanceof LegalDocumentProfile) {
                $blockers[(int) $document->id] = $this->requiredRequisitesBlocker();

                continue;
            }

            try {
                $this->validator->validate($profile, (array) $document->structured_fields);
            } catch (ValidationException|InvalidArgumentException) {
                $blockers[(int) $document->id] = $this->requiredRequisitesBlocker();
            }
        }

        return $blockers;
    }

    private function profileCode(LegalArchiveDocument $document): string
    {
        return trim((string) ($document->type_profile_code ?: $document->document_type));
    }

    /**
     * @param  Collection<int, LegalArchiveDocument>  $documents
     * @return array<int, string>
     */
    private function allBlocked(Collection $documents): array
    {
        return $documents->mapWithKeys(
            fn (LegalArchiveDocument $document): array => [(int) $document->id => $this->requiredRequisitesBlocker()],
        )->all();
    }

    private function requiredRequisitesBlocker(): string
    {
        if (Container::getInstance()->bound('translator')) {
            return trans_message('legal_archive.workflow.blockers.required_requisites_missing');
        }

        return 'legal_archive.workflow.blockers.required_requisites_missing';
    }
}
