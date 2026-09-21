<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentLegalArchiveVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Exceptions\BusinessLogicException;
use App\Models\ConstructionJournal;
use App\Services\LegalArchive\Signatures\SignerIdentitySet;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

final class GeneralJournalSignedDocumentRows
{
    public function rows(ConstructionJournal $journal, array $versionIds): array
    {
        $ids = $this->versionIds($versionIds);
        $versions = ExecutiveDocumentVersion::query()
            ->whereIn('id', $ids)
            ->where('organization_id', (int) $journal->organization_id)
            ->whereHas('document', function ($query) use ($journal): void {
                $query->where('organization_id', (int) $journal->organization_id)
                    ->where('project_id', (int) $journal->project_id);
            })
            ->with('document')
            ->get()
            ->keyBy('id');
        if ($versions->count() !== count($ids)) {
            $this->fail(404);
        }

        $mappings = ExecutiveDocumentLegalArchiveVersion::query()
            ->where('organization_id', (int) $journal->organization_id)
            ->whereIn('executive_document_version_id', $ids)
            ->with([
                'legalArchiveVersion.document.signatures.request',
                'legalArchiveVersion.document.signatures.verificationHistory',
            ])
            ->get()
            ->keyBy('executive_document_version_id');

        $rows = [];
        $sources = [];
        foreach ($ids as $id) {
            $version = $versions->get($id);
            $mapping = $mappings->get($id);
            if (! $version instanceof ExecutiveDocumentVersion || ! $mapping instanceof ExecutiveDocumentLegalArchiveVersion) {
                $this->fail(404);
            }
            $archiveVersion = $mapping->legalArchiveVersion;
            if (! $archiveVersion instanceof LegalArchiveDocumentVersion) {
                $this->fail(404);
            }
            $document = $version->document;
            $archive = $archiveVersion->document;
            if ($document === null || $archive === null
                || (int) $mapping->organization_id !== (int) $journal->organization_id
                || (int) $version->organization_id !== (int) $journal->organization_id
                || (int) $document->organization_id !== (int) $journal->organization_id
                || (int) $document->project_id !== (int) $journal->project_id
                || (int) $archiveVersion->organization_id !== (int) $journal->organization_id
                || (int) $archive->organization_id !== (int) $journal->organization_id
                || (int) $archive->primary_project_id !== (int) $journal->project_id
                || (string) $archive->source_type !== 'executive_document'
                || (string) $archive->source_id !== (string) $document->id
                || (int) $archiveVersion->document_id !== (int) $archive->id
                || (int) $mapping->legal_archive_document_version_id !== (int) $archiveVersion->id
                || preg_match('/^[a-f0-9]{64}$/i', (string) $version->content_hash) !== 1
                || ! hash_equals((string) $version->content_hash, (string) $mapping->source_content_hash)
                || ! hash_equals((string) $version->content_hash, (string) $archiveVersion->content_hash)
                || (string) $archiveVersion->processing_status !== 'ready') {
                $this->fail(422);
            }

            $metadata = is_array($archiveVersion->metadata) ? $archiveVersion->metadata : [];
            if ((int) ($metadata['executive_document_version_id'] ?? 0) !== (int) $version->id
                || ! hash_equals((string) $version->content_hash, (string) ($metadata['executive_document_content_hash'] ?? ''))) {
                $this->fail(422);
            }

            $signatures = $this->signedSignatures($archiveVersion);
            $basis = is_array($version->basis_snapshot) ? $version->basis_snapshot : [];
            $documentSnapshot = is_array($basis['document'] ?? null) ? $basis['document'] : [];
            if ($documentSnapshot === []) {
                $this->fail(422);
            }
            $rows[] = [
                'document' => $this->documentText($documentSnapshot, (array) $version->profile_snapshot),
                'signed' => $this->signatureText($signatures),
            ];
            $signatureIds = array_map(static fn (array $signature): int => $signature['id'], $signatures);
            $sources[] = [
                'source_type' => 'executive_document_version',
                'source_version_id' => (int) $version->id,
                'source_version_number' => (string) $version->version_number,
                'content_hash' => (string) $version->content_hash,
                'basis_snapshot' => $basis,
                'legal_archive_document_id' => (int) $archive->id,
                'legal_archive_document_version_id' => (int) $archiveVersion->id,
                'legal_archive_content_hash' => (string) $archiveVersion->content_hash,
                'signature_ids' => $signatureIds,
                'signatures' => $signatures,
                'signature_id' => $signatures[0]['id'],
                'signed_content_hash' => $signatures[0]['signed_content_hash'],
                'archive_metadata' => $metadata,
            ];
        }

        return ['rows' => $rows, 'sources' => $sources];
    }

    private function versionIds(array $versionIds): array
    {
        if (count($versionIds) > 500) {
            $this->fail(422);
        }
        $ids = [];
        foreach ($versionIds as $id) {
            if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                $this->fail(422);
            }
            $ids[] = (int) $id;
        }
        if (count($ids) !== count(array_unique($ids))) {
            $this->fail(422);
        }

        return $ids;
    }

    private function signedSignatures(LegalArchiveDocumentVersion $version): array
    {
        $document = $version->document;
        $signatures = $document?->signatures
            ?->where('document_version_id', (int) $version->id)
            ->values() ?? new Collection;
        $valid = [];
        foreach ($signatures as $signature) {
            if ($signature->revocation_reason !== null) {
                $this->fail(422);
            }
            $request = $signature->request;
            if ($request === null || (string) $request->status !== 'completed'
                || (int) $signature->organization_id !== (int) $version->organization_id
                || (int) $signature->document_id !== (int) $version->document_id
                || (int) $request->organization_id !== (int) $version->organization_id
                || (int) $request->document_id !== (int) $version->document_id
                || (int) $request->document_version_id !== (int) $version->id
                || !$signature->authority_confirmed || $signature->signed_at === null
                || ! hash_equals((string) $version->content_hash, (string) $signature->signed_content_hash)) {
                continue;
            }
            try {
                $signerSet = SignerIdentitySet::fromSnapshot((array) $signature->signers);
                $expected = SignerIdentitySet::fromSnapshot((array) $request->signers);
                if (!$signerSet->equals($expected)
                    || !hash_equals($signerSet->hash(), (string) $signature->signer_snapshot_hash)
                    || !hash_equals($expected->hash(), (string) $request->signer_snapshot_hash)) {
                    continue;
                }
            } catch (DomainException) {
                continue;
            }
            if ((string) $signature->method === 'paper'
                && (string) $signature->signature_kind === 'paper_original'
                && (string) $signature->verification_status === 'registered') {
                $valid[] = [
                    'id' => (int) $signature->id,
                    'kind' => 'paper_original',
                    'signed_at' => $signature->signed_at?->toIso8601String(),
                    'verified_at' => null,
                    'signers' => (array) $signature->signers,
                    'signer_name' => (string) $signature->signer_name,
                    'signed_content_hash' => (string) $signature->signed_content_hash,
                ];
                continue;
            }
            $verification = $signature->verificationHistory->sortByDesc('id')->first();
            if ($verification !== null && (string) $verification->status === 'revoked') {
                $this->fail(422);
            }
            if ((string) $signature->method !== 'paper'
                && (string) $signature->verification_status === 'verified'
                && (bool) $signature->authority_confirmed
                && $verification !== null
                && (string) $verification->status === 'verified'
                && $verification->revocation_reason === null
                && hash_equals((string) $version->content_hash, (string) $verification->signed_content_hash)) {
                $valid[] = [
                    'id' => (int) $signature->id,
                    'kind' => (string) $signature->signature_kind,
                    'signed_at' => $signature->signed_at?->toIso8601String(),
                    'verified_at' => $verification->verified_at?->toIso8601String(),
                    'signers' => (array) $signature->signers,
                    'signer_name' => (string) $signature->signer_name,
                    'signed_content_hash' => (string) $signature->signed_content_hash,
                ];
            }
        }
        if ($valid === []) {
            $this->fail(422);
        }

        return $valid;
    }

    private function documentText(array $snapshot, array $profile): string
    {
        $parts = [];
        foreach (['title', 'section_name', 'work_type_name'] as $key) {
            $value = $snapshot[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = trim((string) $value);
            }
        }
        foreach (['document_date', 'inspection_date'] as $key) {
            if (!empty($snapshot[$key])) {
                $parts[] = $this->dateText((string) $snapshot[$key]);
            }
        }
        foreach (['act_number', 'drawing_set_code', 'presented_works', 'presented_structures', 'structure_location', 'network_section_boundaries', 'geodetic_base_description'] as $key) {
            if (is_scalar($profile[$key] ?? null) && trim((string) $profile[$key]) !== '') {
                $parts[] = trim((string) $profile[$key]);
            }
        }

        return implode('; ', array_unique($parts));
    }

    private function signatureText(array $signatures): string
    {
        $parts = [];
        foreach ($signatures as $signature) {
            $signerNames = [];
            foreach ($signature['signers'] as $signer) {
                if (is_array($signer) && is_scalar($signer['name'] ?? null)) {
                    $signerNames[] = trim((string) ($signer['position'] ?? '').' '.(string) $signer['name']);
                }
            }
            $parts[] = implode(', ', array_filter([
                $this->signatureKindText((string) $signature['kind']),
                $signerNames === [] ? null : implode(', ', array_unique($signerNames)),
                $this->dateText((string) $signature['signed_at']),
            ]));
        }

        return implode(' | ', $parts);
    }

    private function signatureKindText(string $kind): string
    {
        return match ($kind) {
            'paper_original' => trans_message('general_journal.paper_original'),
            default => trans_message('general_journal.electronic_signature'),
        };
    }

    private function dateText(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->format('d.m.Y');
        } catch (\Throwable) {
            $this->fail(422);
        }
    }

    private function fail(int $status): never
    {
        throw new BusinessLogicException(trans_message('executive_documentation.errors.version_not_found'), $status);
    }
}
