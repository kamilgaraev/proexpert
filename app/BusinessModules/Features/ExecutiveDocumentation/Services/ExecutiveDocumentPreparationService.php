<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Support\ExecutiveDocumentProfileRegistry;
use App\Exceptions\BusinessLogicException;
use App\Services\LegalArchive\CanonicalJson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExecutiveDocumentPreparationService
{
    public function __construct(
        private readonly ExecutiveDocumentMutationGuard $guard,
        private readonly ExecutiveDocumentRenderService $renderer,
        private readonly ExecutiveDocumentationService $documents,
        private readonly ExecutiveDocumentRelationSnapshot $relations,
    ) {}

    public function compose(ExecutiveDocumentSet $set, int $actorId, array $data): ExecutiveDocument
    {
        $this->guard->assertSetActor($set, $actorId, 'executive-documentation.create');
        if (! in_array($data['document_type'] ?? null, [
            'hidden_work_act', 'axis_layout_act', 'geodetic_base_acceptance_act',
            'responsible_structure_act', 'engineering_network_section_act',
        ], true)) {
            throw ValidationException::withMessages(['document_type' => 'Выберите одну из пяти форм актов.']);
        }
        $input = app(ExecutiveDocumentInput::class);
        $data = $input->validateMapping($data, $set);
        $input->validatePreparedProfile($data);
        $invalid = app(ExecutiveDocumentProfileRegistry::class)->validateProfileData($data['document_type'], $data['profile_data']);
        if ($invalid !== []) {
            throw ValidationException::withMessages(['profile_data' => 'Проверьте реквизиты составляемого акта.']);
        }
        $requiredRoles = ['developer_control_representative', 'construction_representative', 'contractor_control_representative'];
        $items = collect($set->approvedList()->first()?->items ?? []);
        $item = $items->first(static fn (array $row): bool => ($row['profile_type'] ?? null) === $data['document_type']
            && ($row['completed_work_id'] ?? null) === ($data['completed_work_id'] ?? null));
        $conditions = is_array($item['conditions'] ?? null) ? $item['conditions'] : [];
        if (($conditions['designer_supervision'] ?? false) === true) {
            $requiredRoles[] = 'designer_representative';
        }
        if (($conditions['separate_executor'] ?? false) === true) {
            $requiredRoles[] = match ($data['document_type']) {
                'hidden_work_act' => 'direct_work_executor',
                'axis_layout_act' => 'axis_layout_executor',
                'geodetic_base_acceptance_act' => 'geodetic_base_executor',
                'responsible_structure_act' => 'structure_executor',
                'engineering_network_section_act' => 'network_executor',
            };
            if ($data['document_type'] === 'engineering_network_section_act') {
                $requiredRoles[] = 'operating_company_representative';
            }
        }
        foreach ($requiredRoles as $role) {
            $signatory = collect($data['signatories'] ?? [])->first(static fn ($row): bool => is_array($row) && ($row['role'] ?? null) === $role);
            if (! is_array($signatory) || trim((string) ($signatory['name'] ?? '')) === ''
                || trim((string) ($signatory['organization'] ?? '')) === ''
                || trim((string) ($signatory['authority_document'] ?? '')) === '') {
                throw ValidationException::withMessages(['signatories.'.$role => 'Укажите имя, организацию и основание полномочий участника акта.']);
            }
        }
        $snapshot = [
            'document_type' => $data['document_type'],
            'document' => [
                'title' => $data['title'], 'document_type' => $data['document_type'],
                'document_date' => $data['document_date'] ?? null,
                'signatories' => $data['signatories'] ?? [],
            ],
            'project' => $set->project()->firstOrFail()->only(['id', 'name', 'address']),
            'profile_data' => $data['profile_data'],
            'relations' => $data['relations'] ?? [],
            'source_version_number' => '1.0',
        ];
        $pdf = $this->renderer->renderSnapshot($snapshot, ExecutiveDocumentRenderService::TEMPLATE_VERSION);
        $path = tempnam(sys_get_temp_dir(), 'itd-compose-');
        if ($path === false) {
            throw new \RuntimeException('executive_document_temp_file_failed');
        }
        try {
            if (file_put_contents($path, $pdf) !== strlen($pdf)) {
                throw new \RuntimeException('executive_document_temp_write_failed');
            }
            $data['__generated_preparation'] = true;
            $data['initial_version'] = [
                'version_number' => '1.0',
                'file' => new UploadedFile($path, 'executive-act.pdf', 'application/pdf', null, true),
                'metadata' => ['template_version' => ExecutiveDocumentRenderService::TEMPLATE_VERSION, 'print_snapshot' => $snapshot],
            ];

            return $this->documents->addDocument($set, $actorId, $data);
        } finally {
            unlink($path);
        }
    }

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
            app(ExecutiveDocumentInput::class)->validatePreparedProfile([
                'document_type' => $document->document_type->value,
                'work_type_id' => $document->work_type_id,
                'journal_entry_id' => $document->journal_entry_id,
                'profile_data' => $document->profile_data ?? [],
                'relations' => $document->relations()->get(['relation_type', 'target_type', 'target_id'])->toArray(),
            ]);
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
