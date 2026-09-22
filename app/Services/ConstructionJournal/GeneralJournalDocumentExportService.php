<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\GeneralJournalDocumentVersion;
use App\Models\JournalExport;
use App\Models\Organization;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\Storage\FileService;
use DomainException;
use RuntimeException;

final class GeneralJournalDocumentExportService
{
    public function __construct(
        private readonly FileService $files,
        private readonly GeneralJournalDocumentRenderService $renderer,
    ) {}

    public function write(JournalExport $export): string
    {
        $version = GeneralJournalDocumentVersion::query()
            ->where('organization_id', $export->organization_id)
            ->where('project_id', $export->project_id)
            ->where('journal_id', $export->journal_id)
            ->findOrFail($export->options['document_version_id'] ?? 0);
        if ($export->type !== 'general' || $export->format !== 'pdf'
            || !hash_equals($version->snapshot_hash, CanonicalJson::fingerprint($version->source_snapshot))) {
            throw new DomainException('general_journal_snapshot_mismatch');
        }
        $content = $this->renderer->renderSnapshot($version->source_snapshot);
        $path = $this->files->putContent(
            $content, 'exports/journal/general', 'GeneralJournal-'.$version->id.'-'.$export->id.'.pdf',
            'private', Organization::query()->findOrFail($version->organization_id),
        );
        if ($path === false) {
            throw new RuntimeException('general_journal_storage_failed');
        }
        return $path;
    }
}
