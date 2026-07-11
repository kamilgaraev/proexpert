<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\Models\Organization;
use App\Services\Storage\FileService;
use DomainException;

final readonly class S3PipelineArtifactStore implements PipelineArtifactStore
{
    private const MAX_ARTIFACT_BYTES = 8_388_608;

    public function __construct(private FileService $files) {}

    public function write(PipelineContext $context, ProcessingStage $stage, array $data): PipelineStageOutput
    {
        $content = CanonicalPipelineJson::encode($data);
        $bytes = strlen($content);
        if ($bytes > self::MAX_ARTIFACT_BYTES) {
            throw new DomainException('Pipeline artifact exceeds its bounded size.');
        }
        $version = 'sha256:'.hash('sha256', $content);
        $organization = $this->organization($context->organizationId);
        $directory = sprintf('ai-estimator/sessions/%d/attempts/%s', $context->sessionId, $context->inputVersion);
        $filename = $stage->value.'-'.substr($version, 7).'.json';
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

        return PipelineStageOutput::create($stage, 1, [
            'artifact_kind' => 's3_json_v1',
            'object_key' => $path,
            'content_version' => $version,
            'bytes' => $bytes,
        ]);
    }

    public function read(PipelineContext $context, PipelineStageOutput $reference): array
    {
        $data = $reference->data;
        $path = $data['object_key'] ?? null;
        $version = $data['content_version'] ?? null;
        $bytes = $data['bytes'] ?? null;
        $prefix = sprintf('org-%d/ai-estimator/sessions/%d/attempts/%s/', $context->organizationId, $context->sessionId, $context->inputVersion);
        if (($data['artifact_kind'] ?? null) !== 's3_json_v1'
            || ! is_string($path) || ! str_starts_with($path, $prefix)
            || ! is_string($version) || preg_match('/\Asha256:[0-9a-f]{64}\z/', $version) !== 1
            || ! is_int($bytes) || $bytes < 2 || $bytes > self::MAX_ARTIFACT_BYTES) {
            throw new DomainException('Pipeline artifact reference is invalid.');
        }
        $content = $this->files->disk($this->organization($context->organizationId))->get($path);
        if (! is_string($content) || strlen($content) !== $bytes || ! hash_equals($version, 'sha256:'.hash('sha256', $content))) {
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
