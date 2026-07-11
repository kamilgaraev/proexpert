<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\Models\Organization;
use App\Services\Storage\FileService;
use DomainException;

final readonly class S3PipelineArtifactStore implements PipelineArtifactStore
{
    public function __construct(private FileService $files) {}

    public function write(PipelineContext $context, StageDefinition $definition, array $data): PipelineArtifactReference
    {
        $content = CanonicalPipelineJson::encode($data);
        $bytes = strlen($content);
        if ($bytes > $definition->maxArtifactBytes) {
            throw new DomainException('Pipeline artifact exceeds its bounded size.');
        }
        $version = 'sha256:'.hash('sha256', $content);
        $organization = $this->organization($context->organizationId);
        $directory = sprintf('ai-estimator/sessions/%d/attempts/%s', $context->sessionId, $context->generationAttemptId ?? 'legacy');
        $filename = $definition->stage->value.'-'.substr($version, 7).'.json';
        $path = sprintf('org-%d/%s/%s', $context->organizationId, $directory, $filename);
        $disk = $this->files->disk($organization);
        if ($disk->exists($path)) {
            $existing = $disk->get($path);
            if (! is_string($existing) || ! hash_equals($version, 'sha256:'.hash('sha256', $existing))) {
                throw new DomainException('Pipeline artifact key collision was detected.');
            }
        } else {
            $stored = $this->files->putContent($content, $directory, $filename, 'private', $organization);
            if (! is_string($stored) || ! hash_equals($path, $stored)) {
                throw new DomainException('Pipeline artifact could not be persisted.');
            }
        }

        return new PipelineArtifactReference('s3_json_v1', $path, $version, $bytes);
    }

    public function read(PipelineContext $context, PipelineArtifactReference $reference): array
    {
        $path = $reference->objectKey;
        $prefix = sprintf('org-%d/ai-estimator/sessions/%d/attempts/%s/', $context->organizationId, $context->sessionId, $context->generationAttemptId ?? 'legacy');
        if ($reference->kind !== 's3_json_v1' || ! str_starts_with($path, $prefix)) {
            throw new DomainException('Pipeline artifact reference is invalid.');
        }
        $content = $this->files->disk($this->organization($context->organizationId))->get($path);
        if (! is_string($content) || strlen($content) !== $reference->bytes || ! hash_equals($reference->contentVersion, 'sha256:'.hash('sha256', $content))) {
            throw new DomainException('Pipeline artifact integrity check failed.');
        }
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new DomainException('Pipeline artifact payload is invalid.');
        }

        return $decoded;
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
