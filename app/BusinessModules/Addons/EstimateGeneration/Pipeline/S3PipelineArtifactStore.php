<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\Models\Organization;
use App\Services\Storage\FileService;
use DomainException;

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
        $stored = $this->files->putImmutable($path, $content, 'application/json');
        if (! hash_equals($path, $stored['path']) || $stored['size'] !== $bytes
            || ! hash_equals(substr($version, 7), $stored['sha256']) || ! hash_equals($content, $stored['body'])) {
            throw new DomainException('Pipeline artifact key collision was detected.');
        }

        return new PipelineArtifactReference('s3_json_v1', $path, $version, $bytes, (string) $stored['version_id']);
    }

    public function read(PipelineContext $context, PipelineArtifactReference $reference): array
    {
        $path = $reference->objectKey;
        $prefix = sprintf('org-%d/estimate-generation/sessions/%d/pipeline/attempts/%s/', $context->organizationId, $context->sessionId, $this->attemptId($context));
        if ($reference->kind !== 's3_json_v1' || ! str_starts_with($path, $prefix)) {
            throw new DomainException('Pipeline artifact reference is invalid.');
        }
        $content = $this->reader->read(
            $context->organizationId,
            $path,
            PipelineDefinitionGraph::MAX_TOTAL_ARTIFACT_BYTES,
            $reference->bytes,
            $reference->contentVersion,
            $reference->versionId,
        )->body;
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new DomainException('Pipeline artifact payload is invalid.');
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
}
