<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectLocatorException;
use App\BusinessModules\Addons\EstimateGeneration\Storage\S3ObjectTransportException;
use App\Models\Organization;
use App\Services\Storage\Exceptions\VersionedObjectIntegrityException;
use App\Services\Storage\Exceptions\VersionedObjectTransportException;
use App\Services\Storage\FileService;
use DomainException;
use JsonException;

final readonly class S3PipelineArtifactStore implements PipelineArtifactStore
{
    public function __construct(
        private FileService $files,
        private BoundedVersionedS3ObjectReader $reader,
    ) {}

    public function write(PipelineContext $context, StageDefinition $definition, array $data): PipelineArtifactReference
    {
        $content = CanonicalPipelineJson::encode($data);
        $bytes = strlen($content);
        if ($bytes > $definition->maxArtifactBytes) {
            throw new DomainException('Pipeline artifact exceeds its bounded size.');
        }
        $version = 'sha256:'.hash('sha256', $content);
        $this->organization($context->organizationId);
        $attemptId = $this->attemptId($context);
        $directory = sprintf('estimate-generation/sessions/%d/pipeline/attempts/%s', $context->sessionId, $attemptId);
        $filename = $definition->stage->value.'-'.substr($version, 7).'.json';
        $path = sprintf('org-%d/%s/%s', $context->organizationId, $directory, $filename);
        try {
            $stored = $this->files->putImmutable($path, $content, 'application/json');
        } catch (VersionedObjectIntegrityException $exception) {
            throw $this->integrityFailure($exception);
        } catch (VersionedObjectTransportException $exception) {
            throw $this->storageFailure($exception);
        }
        if (! hash_equals($path, $stored['path']) || $stored['size'] !== $bytes
            || ! hash_equals(substr($version, 7), $stored['sha256']) || ! hash_equals($content, $stored['body'])) {
            throw $this->integrityFailure();
        }

        return new PipelineArtifactReference('s3_json_v1', $path, $version, $bytes, (string) $stored['version_id']);
    }

    public function read(PipelineContext $context, PipelineArtifactReference $reference): array
    {
        $path = $reference->objectKey;
        $prefix = sprintf('org-%d/estimate-generation/sessions/%d/pipeline/attempts/%s/', $context->organizationId, $context->sessionId, $this->attemptId($context));
        if ($reference->kind !== 's3_json_v1' || ! str_starts_with($path, $prefix)) {
            throw $this->integrityFailure();
        }
        try {
            $content = $this->reader->read(
                $context->organizationId,
                $path,
                PipelineDefinitionGraph::MAX_TOTAL_ARTIFACT_BYTES,
                $reference->bytes,
                $reference->contentVersion,
                $reference->versionId,
            )->body;
        } catch (S3ObjectLocatorException $exception) {
            throw $this->integrityFailure($exception);
        } catch (S3ObjectTransportException $exception) {
            throw $this->storageFailure($exception);
        }

        try {
            $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->integrityFailure($exception);
        }
        if (! is_array($decoded)) {
            throw $this->integrityFailure();
        }

        return $decoded;
    }

    private function attemptId(PipelineContext $context): string
    {
        if ($context->generationAttemptId === null) {
            throw new DomainException('generation_attempt_required');
        }

        return $context->generationAttemptId;
    }

    private function organization(int $organizationId): Organization
    {
        $organization = Organization::query()->find($organizationId);
        if (! $organization instanceof Organization) {
            throw new DomainException('Pipeline organization is unavailable.');
        }

        return $organization;
    }

    private function storageFailure(?\Throwable $previous = null): TypedFailureException
    {
        return new TypedFailureException(
            FailureCategory::Recoverable,
            'pipeline_artifact_storage_unavailable',
            previous: $previous,
        );
    }

    private function integrityFailure(?\Throwable $previous = null): TypedFailureException
    {
        return new TypedFailureException(
            FailureCategory::Terminal,
            'pipeline_artifact_integrity_failed',
            previous: $previous,
        );
    }
}
