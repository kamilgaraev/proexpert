<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use ZipArchive;

final class LegalDocumentBlankDraftService
{
    public function __construct(
        private readonly LegalDocumentFileService $files,
        private readonly LegalDocumentEditorSessionService $sessions,
        private readonly LegalDocumentEditor $editor,
        private readonly LegalDocumentAuthorizer $authorizer,
    ) {}

    /** @return array{version: LegalArchiveDocumentVersion, session: EditorSessionPayload} */
    public function start(LegalArchiveDocument $document, User $actor, string $title, int $lockVersion): array
    {
        $this->authorizer->authorizePermission($actor, $document, 'legal_archive.files.upload');
        $this->authorizer->authorizePermission($actor, $document, 'legal_archive.versions.create');
        $this->authorizer->authorize($actor, $document, 'edit');
        if (! $this->editor->enabled()) {
            throw new DomainException('legal_document_editor_disabled');
        }

        $file = $this->resolvePrimaryFile($document, $title, $lockVersion);

        $path = $this->createDocx($title);
        try {
            $upload = new UploadedFile($path, $this->fileName($title), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
            $version = $this->files->addVersion($file, $upload, new VersionInput(
                uploadedByUserId: (int) $actor->id,
                metadata: ['source' => 'editor_blank_draft'],
                expectedDocumentLockVersion: $lockVersion,
            ));
        } finally {
            @unlink($path);
        }

        return ['version' => $version, 'session' => $this->sessions->open($version, $actor, 'edit')];
    }

    private function createDocx(string $title): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-editor-');
        if (! is_string($path)) {
            throw new DomainException('legal_document_editor_draft_unavailable');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new DomainException('legal_document_editor_draft_unavailable');
        }
        $safeTitle = htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'. $safeTitle .'</w:t></w:r></w:p><w:sectPr/></w:body></w:document>');
        $zip->close();

        return $path;
    }

    private function resolvePrimaryFile(LegalArchiveDocument $document, string $title, int $lockVersion): LegalArchiveDocumentFile
    {
        return DB::connection()->transaction(function () use ($document, $title, $lockVersion): LegalArchiveDocumentFile {
            $connection = DB::connection();
            $lockedDocument = (new LegalDocumentAggregateLock)->lockDocument(
                $connection,
                (int) $document->organization_id,
                (int) $document->id,
            );
            if ((int) $lockedDocument->lock_version !== $lockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
            }
            $file = LegalArchiveDocumentFile::query()
                ->where('organization_id', $lockedDocument->organization_id)
                ->where('document_id', $lockedDocument->id)
                ->where('role', 'primary')
                ->lockForUpdate()
                ->first();
            if ($file instanceof LegalArchiveDocumentFile) {
                return $file;
            }

            return LegalArchiveDocumentFile::query()->create([
                'document_id' => $lockedDocument->id,
                'organization_id' => $lockedDocument->organization_id,
                'role' => 'primary',
                'title' => $title,
                'sort_order' => ((int) LegalArchiveDocumentFile::query()
                    ->where('document_id', $lockedDocument->id)->max('sort_order')) + 1,
                'is_required' => true,
            ]);
        }, 3);
    }

    private function fileName(string $title): string
    {
        $name = trim(preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $title) ?? '');

        return ($name === '' ? 'new-document' : mb_substr($name, 0, 120)).'.docx';
    }
}
