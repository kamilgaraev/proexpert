<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\Exceptions\BusinessLogicException;
use App\Services\LegalArchive\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final class ExecutiveDocumentPreparationService
{
    public function __construct(
        private readonly ExecutiveDocumentMutationGuard $guard,
        private readonly ExecutiveDocumentRenderService $renderer,
        private readonly ExecutiveDocumentationService $documents,
        private readonly ExecutiveDocumentRelationSnapshot $relations,
    ) {}

    public function prepare(int $documentId, int $actorId, array $data): ExecutiveDocumentVersion
    {
        $initial = ExecutiveDocument::query()->findOrFail($documentId);
        return DB::transaction(function () use ($initial, $actorId, $data): ExecutiveDocumentVersion {
            ExecutiveDocumentSet::query()->whereKey($initial->document_set_id)->lockForUpdate()->firstOrFail();
            $document = ExecutiveDocument::query()->whereKey($initial->id)->lockForUpdate()->firstOrFail();
            $this->guard->assertActor($document, $actorId, 'executive-documentation.edit');
            $key = trim((string) ($data['operation_key'] ?? ''));
            $hash = CanonicalJson::fingerprint([$actorId, $document->id, $data]);
            if ($key === '') {
                $this->conflict();
            }
            $previous = $document->versions()->withTrashed()->where('operation_key', $key)->first();
            if ($previous !== null) {
                if ($previous->trashed() || ($previous->metadata['origin'] ?? null) !== 'generated_preparation'
                    || ($previous->metadata['preparation_request_hash'] ?? null) !== $hash) {
                    $this->conflict();
                }
                return $previous;
            }
            $latest = $document->versions()->first();
            if (! isset($data['expected_version_id'], $data['expected_revision'])
                || (int) $data['expected_version_id'] !== (int) $latest?->id
                || (int) $data['expected_revision'] !== (int) ($latest?->metadata['draft_revision'] ?? 0)) {
                $this->conflict();
            }
            $snapshot = [
                'document_type' => $document->document_type->value,
                'document' => $document->only(['title', 'document_date', 'inspection_date', 'participants', 'signatories', 'copies_count']),
                'project' => $document->project()->firstOrFail()->only(['id', 'name', 'address']),
                'profile_data' => $document->profile_data ?? [],
                'relations' => $this->relations->forDocument($document),
                'source_version_id' => $latest?->id,
                'source_version_number' => $data['version_number'],
            ];
            $snapshot = json_decode(CanonicalJson::encode($snapshot), true, 512, JSON_THROW_ON_ERROR);
            $pdf = $this->renderer->renderSnapshot($snapshot, $data['template_version']);
            $path = tempnam(sys_get_temp_dir(), 'itd-prepare-');
            if ($path === false) {
                throw new \RuntimeException('executive_document_temp_file_failed');
            }
            try {
                if (file_put_contents($path, $pdf) !== strlen($pdf)) {
                    throw new \RuntimeException('executive_document_temp_write_failed');
                }
                return $this->documents->addPreparedVersion($document, $actorId, [
                    'version_number' => $data['version_number'], 'expected_version_id' => $data['expected_version_id'],
                    'operation_key' => $key, 'file' => new UploadedFile($path, 'executive-document.pdf', 'application/pdf', null, true),
                    'metadata' => ['template_version' => $data['template_version'], 'preparation_request_hash' => $hash, 'print_snapshot' => $snapshot],
                ]);
            } finally {
                unlink($path);
            }
        });
    }

    private function conflict(): never
    {
        throw new BusinessLogicException(trans_message('executive_documentation.errors.version_conflict'), 409);
    }
}
