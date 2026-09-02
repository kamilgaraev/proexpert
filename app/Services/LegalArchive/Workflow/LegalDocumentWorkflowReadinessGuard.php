<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Workflow;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Profiles\LegalDocumentProfile;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use Illuminate\Container\Container;
use Illuminate\Database\Query\JoinClause;
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
        $missing = $this->missingAdditionalFilesFor(collect([$document]), [
            (int) $document->organization_id => [$this->profileCode($document) => $profile],
        ]);
        if (isset($missing[(int) $document->id])) {
            throw ValidationException::withMessages(['files' => $this->requiredFilesBlocker()]);
        }
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

        $missingFiles = $this->missingAdditionalFilesFor($documents, $profiles);
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
            if (! isset($blockers[(int) $document->id]) && isset($missingFiles[(int) $document->id])) {
                $blockers[(int) $document->id] = $this->requiredFilesBlocker();
            }
        }

        return $blockers;
    }

    private function profileCode(LegalArchiveDocument $document): string
    {
        return trim((string) ($document->type_profile_code ?: $document->document_type));
    }

    public function missingAdditionalFilesFor(Collection $documents, array $profiles): array
    {
        $required = [];
        $organizations = [];
        foreach ($documents as $document) {
            $profile = $profiles[(int) $document->organization_id][$this->profileCode($document)] ?? null;
            if (! $profile instanceof LegalDocumentProfile) {
                continue;
            }
            $roles = array_values(array_diff($profile->requiredFileRoles, ['primary']));
            if ($roles !== []) {
                $required[(int) $document->id] = $roles;
                $organizations[(int) $document->id] = (int) $document->organization_id;
            }
        }
        if ($required === []) {
            return [];
        }

        $files = $documents->first()->getConnection()
            ->table('legal_archive_document_files as files')
            ->join('legal_archive_document_versions as versions', static function (JoinClause $join): void {
                $join->on('versions.id', '=', 'files.current_version_id')
                    ->on('versions.document_file_id', '=', 'files.id')
                    ->on('versions.document_id', '=', 'files.document_id')
                    ->on('versions.organization_id', '=', 'files.organization_id');
            })
            ->whereIn('files.document_id', array_keys($required))
            ->whereIn('files.organization_id', array_values(array_unique($organizations)))
            ->where('versions.is_current', true)
            ->where('versions.processing_status', 'ready')
            ->get(['files.document_id', 'files.organization_id', 'files.role', 'versions.content_hash']);

        foreach ($files as $file) {
            $id = (int) $file->document_id;
            if ($organizations[$id] !== (int) $file->organization_id
                || preg_match('/^[a-f0-9]{64}$/D', (string) $file->content_hash) !== 1) {
                continue;
            }
            $required[$id] = array_values(array_diff($required[$id], [(string) $file->role]));
        }

        return array_filter($required, static fn (array $roles): bool => $roles !== []);
    }

    private function requiredFilesBlocker(): string
    {
        if (Container::getInstance()->bound('translator')) {
            return trans_message('legal_archive.workflow.blockers.required_files_missing');
        }

        return 'legal_archive.workflow.blockers.required_files_missing';
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
