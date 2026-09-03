<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Services\LegalArchive\Profiles\LegalDocumentProfile;
use Illuminate\Container\Container;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

final class LegalDocumentFileRequirements
{
    public function forDocuments(Collection $documents, array $profiles): array
    {
        $rolesByDocument = [];
        foreach ($documents as $document) {
            $code = trim((string) ($document->type_profile_code ?: $document->document_type));
            $profile = $profiles[(int) $document->organization_id][$code] ?? null;
            if ($profile instanceof LegalDocumentProfile) {
                $roles = array_values(array_unique(array_diff($profile->requiredFileRoles, ['primary'])));
                if ($roles !== []) {
                    $rolesByDocument[(int) $document->id] = $roles;
                }
            }
        }
        if ($rolesByDocument === []) {
            return [];
        }

        $ready = $this->readyVersionsFor($documents->filter(
            static fn (LegalArchiveDocument $document): bool => isset($rolesByDocument[(int) $document->id]),
        ))->groupBy('document_id');
        $requirements = [];
        foreach ($rolesByDocument as $documentId => $roles) {
            $readyRoles = $ready->get($documentId, collect())->pluck('role')->all();
            foreach ($roles as $index => $role) {
                $requirements[$documentId][] = [
                    'role' => $role,
                    'label' => $this->label($role, $index + 1),
                    'ready' => in_array($role, $readyRoles, true),
                ];
            }
        }

        return $requirements;
    }

    public function missingFor(Collection $documents, array $profiles): array
    {
        $missing = [];
        foreach ($this->forDocuments($documents, $profiles) as $documentId => $requirements) {
            foreach ($requirements as $requirement) {
                if (! $requirement['ready']) {
                    $missing[$documentId][] = $requirement['role'];
                }
            }
        }

        return $missing;
    }

    public function snapshotFor(LegalArchiveDocument $document): array
    {
        return $this->readyVersionsFor(collect([$document]))->map(static fn (object $file): array => [
            'file_id' => (int) $file->file_id,
            'role' => (string) $file->role,
            'title' => (string) $file->title,
            'version_id' => (int) $file->version_id,
            'content_hash' => (string) $file->content_hash,
        ])->values()->all();
    }

    private function readyVersionsFor(Collection $documents): Collection
    {
        if ($documents->isEmpty()) {
            return collect();
        }
        $organizations = $documents->mapWithKeys(static fn (LegalArchiveDocument $document): array => [
            (int) $document->id => (int) $document->organization_id,
        ])->all();

        return $documents->first()->getConnection()
            ->table('legal_archive_document_files as files')
            ->join('legal_archive_document_versions as versions', static function (JoinClause $join): void {
                $join->on('versions.id', '=', 'files.current_version_id')
                    ->on('versions.document_file_id', '=', 'files.id')
                    ->on('versions.document_id', '=', 'files.document_id')
                    ->on('versions.organization_id', '=', 'files.organization_id');
            })
            ->whereIn('files.document_id', array_keys($organizations))
            ->whereIn('files.organization_id', array_values(array_unique($organizations)))
            ->where('versions.is_current', true)
            ->where('versions.processing_status', 'ready')
            ->orderBy('files.id')
            ->get(['files.id as file_id', 'files.document_id', 'files.organization_id', 'files.role',
                'files.title', 'versions.id as version_id', 'versions.content_hash'])
            ->filter(static fn (object $file): bool => $organizations[(int) $file->document_id] === (int) $file->organization_id
                && preg_match('/^[a-f0-9]{64}$/D', (string) $file->content_hash) === 1);
    }

    private function label(string $role, int $number): string
    {
        $key = in_array($role, ['appendix', 'attachment', 'specification', 'power_of_attorney'], true)
            ? 'legal_archive.required_file_labels.'.$role
            : 'legal_archive.required_file_labels.other';

        return Container::getInstance()->bound('translator') ? trans_message($key, ['number' => $number]) : $key;
    }
}
