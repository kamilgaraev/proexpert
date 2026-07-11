<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentDocumentProcessingUnitStore implements DocumentProcessingUnitStore
{
    public function __construct(private Connection $database) {}

    public function create(int $organizationId, int $projectId, int $sessionId, int $documentId, DocumentUnitData $unit): DocumentProcessingUnitRecord
    {
        $model = $this->query()->firstOrCreate(
            ['document_id' => $documentId, 'unit_type' => $unit->type->value, 'unit_index' => $unit->index, 'source_version' => $unit->sourceVersion],
            ['organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId, 'status' => DocumentProcessingUnitStatus::Pending, 'locator' => $unit->locator],
        );

        return $this->record($model);
    }

    public function find(int $unitId): ?DocumentProcessingUnitRecord
    {
        $model = $this->query()->find($unitId);

        return $model instanceof EstimateGenerationProcessingUnit ? $this->record($model) : null;
    }

    public function executionContext(DocumentProcessingUnitClaim $claim): ?DocumentUnitExecutionContext
    {
        $unit = $this->query()->with('document')->find($claim->unitId);

        if (! $unit instanceof EstimateGenerationProcessingUnit
            || ! $unit->document instanceof EstimateGenerationDocument
            || ! $claim->acquired()
            || (string) $unit->claim_token !== $claim->token
            || ! $this->isCurrent($unit, (string) $unit->source_version)) {
            return null;
        }

        return new DocumentUnitExecutionContext(
            (int) $unit->id,
            (int) $unit->organization_id,
            (int) $unit->project_id,
            (int) $unit->session_id,
            (int) $unit->document_id,
            $unit->unit_type,
            (int) $unit->unit_index,
            (string) $unit->source_version,
            (array) $unit->locator,
            (string) $unit->document->storage_path,
            (string) ($unit->document->mime_type ?: 'application/octet-stream'),
            (string) $unit->document->filename,
        );
    }

    public function claim(int $unitId, string $sourceVersion, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt, int $maxAttempts): DocumentProcessingUnitClaim
    {
        return $this->database->transaction(function () use ($unitId, $sourceVersion, $now, $leaseExpiresAt, $maxAttempts): DocumentProcessingUnitClaim {
            $unit = $this->query()->with('document')->lockForUpdate()->find($unitId);

            if (! $this->isCurrent($unit, $sourceVersion)) {
                if ($unit instanceof EstimateGenerationProcessingUnit && $unit->status !== DocumentProcessingUnitStatus::Completed) {
                    $unit->forceFill(['status' => DocumentProcessingUnitStatus::Superseded, 'claim_token' => null, 'lease_expires_at' => null])->save();
                }

                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Stale);
            }

            if ($unit->status === DocumentProcessingUnitStatus::Completed) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::AlreadyCompleted);
            }

            if ($unit->status === DocumentProcessingUnitStatus::Running && $unit->lease_expires_at?->toDateTimeImmutable() > $now) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Busy, busyUntil: $unit->lease_expires_at->toDateTimeImmutable());
            }

            if ((int) $unit->attempt_count >= $maxAttempts || $leaseExpiresAt <= $now) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Exhausted);
            }

            $token = (string) Str::uuid();
            $unit->forceFill([
                'status' => DocumentProcessingUnitStatus::Running,
                'attempt_count' => (int) $unit->attempt_count + 1,
                'claim_token' => $token,
                'lease_expires_at' => $leaseExpiresAt,
                'started_at' => $now,
                'failed_at' => null,
                'failure_code' => null,
                'failure_fingerprint' => null,
            ])->save();

            return new DocumentProcessingUnitClaim(
                $unitId,
                DocumentProcessingUnitClaimStatus::Acquired,
                $token,
                organizationId: (int) $unit->organization_id,
                projectId: (int) $unit->project_id,
                sessionId: (int) $unit->session_id,
                documentId: (int) $unit->document_id,
                sourceVersion: (string) $unit->source_version,
            );
        }, 3);
    }

    public function complete(DocumentProcessingUnitClaim $claim, string $outputVersion, int $outputCount, DateTimeImmutable $now): bool
    {
        return $this->claimQuery($claim)
            ->where('status', DocumentProcessingUnitStatus::Running->value)
            ->where('claim_token', $claim->token)->where('lease_expires_at', '>', $now)
            ->update(['status' => DocumentProcessingUnitStatus::Completed->value, 'output_version' => $outputVersion, 'output_count' => $outputCount, 'claim_token' => null, 'lease_expires_at' => null, 'completed_at' => $now, 'updated_at' => $now]) === 1;
    }

    public function publish(DocumentProcessingUnitClaim $claim, DocumentUnitOutput $output, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($claim, $output, $now): bool {
            $unit = $this->query()->with('document')->lockForUpdate()->find($claim->unitId);

            if (! $unit instanceof EstimateGenerationProcessingUnit
                || ! $this->isCurrent($unit, (string) $unit->source_version)
                || $unit->status !== DocumentProcessingUnitStatus::Running
                || (string) $unit->claim_token !== $claim->token
                || $unit->lease_expires_at?->toDateTimeImmutable() <= $now) {
                return false;
            }

            $this->pageQuery()->updateOrCreate(
                ['document_id' => $unit->document_id, 'page_number' => $unit->unit_index],
                ['processing_unit_id' => $unit->id, 'source_version' => $unit->source_version, 'output_version' => $output->version, 'organization_id' => $unit->organization_id, 'project_id' => $unit->project_id, 'session_id' => $unit->session_id, 'width' => $output->width, 'height' => $output->height, 'rotation' => $output->rotation, 'text' => $output->text, 'text_hash' => $output->text !== '' ? hash('sha256', $output->text) : null, 'confidence' => $output->confidence, 'normalized_payload' => $output->normalizedPayload, 'quality_flags' => []],
            );

            return $this->query()
                ->whereKey($unit->id)
                ->where('organization_id', $unit->organization_id)
                ->where('project_id', $unit->project_id)
                ->where('session_id', $unit->session_id)
                ->where('document_id', $unit->document_id)
                ->where('source_version', $unit->source_version)
                ->where('status', DocumentProcessingUnitStatus::Running->value)
                ->where('claim_token', $claim->token)
                ->where('lease_expires_at', '>', $now)
                ->update([
                    'status' => DocumentProcessingUnitStatus::Completed->value,
                    'output_version' => $output->version,
                    'output_count' => 1,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]) === 1;
        }, 3);
    }

    public function renew(DocumentProcessingUnitClaim $claim, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool
    {
        return $leaseExpiresAt > $now && $this->claimQuery($claim)
            ->where('status', DocumentProcessingUnitStatus::Running->value)
            ->where('claim_token', $claim->token)->where('lease_expires_at', '>', $now)
            ->update(['lease_expires_at' => $leaseExpiresAt, 'updated_at' => $now]) === 1;
    }

    public function fail(DocumentProcessingUnitClaim $claim, string $code, string $fingerprint, DateTimeImmutable $now): bool
    {
        return $this->claimQuery($claim)
            ->where('status', DocumentProcessingUnitStatus::Running->value)
            ->where('claim_token', $claim->token)->where('lease_expires_at', '>', $now)
            ->update(['status' => DocumentProcessingUnitStatus::Failed->value, 'claim_token' => null, 'lease_expires_at' => null, 'failure_code' => $code, 'failure_fingerprint' => $fingerprint, 'failed_at' => $now, 'updated_at' => $now]) === 1;
    }

    public function supersedeDocumentSource(int $documentId, string $currentSourceVersion): void
    {
        $this->query()->where('document_id', $documentId)->where('source_version', '<>', $currentSourceVersion)
            ->whereNotIn('status', [DocumentProcessingUnitStatus::Completed->value, DocumentProcessingUnitStatus::Superseded->value])
            ->update(['status' => DocumentProcessingUnitStatus::Superseded->value, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    private function isCurrent(mixed $unit, string $sourceVersion): bool
    {
        return $unit instanceof EstimateGenerationProcessingUnit
            && $unit->document instanceof EstimateGenerationDocument
            && (string) $unit->source_version === $sourceVersion
            && (string) $unit->document->source_version === $sourceVersion
            && (int) $unit->organization_id === (int) $unit->document->organization_id
            && (int) $unit->project_id === (int) $unit->document->project_id
            && (int) $unit->session_id === (int) $unit->document->session_id
            && $unit->document->status !== 'ignored';
    }

    private function record(EstimateGenerationProcessingUnit $unit): DocumentProcessingUnitRecord
    {
        return new DocumentProcessingUnitRecord((int) $unit->id, (int) $unit->organization_id, (int) $unit->project_id, (int) $unit->session_id, (int) $unit->document_id, new DocumentUnitData($unit->unit_type, (int) $unit->unit_index, (string) $unit->source_version, (array) $unit->locator), $unit->status, (int) $unit->attempt_count, $unit->claim_token, $unit->lease_expires_at?->toDateTimeImmutable(), $unit->output_version, (int) $unit->output_count, $unit->failure_code, $unit->failure_fingerprint);
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function query(): Builder
    {
        $model = new EstimateGenerationProcessingUnit;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationDocumentPage> */
    private function pageQuery(): Builder
    {
        $model = new EstimateGenerationDocumentPage;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function claimQuery(DocumentProcessingUnitClaim $claim): Builder
    {
        return $this->query()
            ->whereKey($claim->unitId)
            ->where('organization_id', $claim->organizationId)
            ->where('project_id', $claim->projectId)
            ->where('session_id', $claim->sessionId)
            ->where('document_id', $claim->documentId)
            ->where('source_version', $claim->sourceVersion);
    }
}
