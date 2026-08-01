<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Catalog;

use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidate;
use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidateManifest;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class YamlWaveOneCandidateManifestLoader
{
    public function __construct(private Draft202012SchemaValidator $schemas) {}

    public function load(string $path, string $schemaPath): WaveOneCandidateManifest
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('wave_one_candidate_manifest_unreadable');
        }
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new RuntimeException('wave_one_candidate_manifest_utf8_invalid');
        }

        try {
            $document = Yaml::parse($bytes);
        } catch (ParseException $exception) {
            throw new RuntimeException('wave_one_candidate_manifest_yaml_invalid', 0, $exception);
        }
        if (! is_array($document) || array_is_list($document)) {
            throw new RuntimeException('wave_one_candidate_manifest_document_invalid');
        }

        $schemaBytes = @file_get_contents($schemaPath);
        if ($schemaBytes === false) {
            throw new RuntimeException('wave_one_candidate_manifest_schema_unreadable');
        }

        try {
            $documentObject = $this->toObjectGraph($document);
            $schema = json_decode($schemaBytes, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('wave_one_candidate_manifest_schema_invalid', 0, $exception);
        }
        if (! is_object($documentObject) || ! is_object($schema)) {
            throw new RuntimeException('wave_one_candidate_manifest_schema_invalid');
        }

        $this->schemas->assertValid($documentObject, $schema, 'most.wave-1-candidates.v1');

        return new WaveOneCandidateManifest(
            $this->string($document, 'catalog'),
            $this->string($document, 'contract_version'),
            new Sha256Hash(hash('sha256', $bytes)),
            $this->candidates($document),
        );
    }

    private function toObjectGraph(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->toObjectGraph($item), $value);
        }

        $object = new \stdClass;
        foreach ($value as $key => $item) {
            $object->{(string) $key} = $this->toObjectGraph($item);
        }

        return $object;
    }

    private function candidates(array $document): array
    {
        $items = $document['candidates'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('wave_one_candidate_manifest_candidates_invalid');
        }

        $candidates = [];
        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                throw new RuntimeException('wave_one_candidate_manifest_candidate_invalid');
            }

            $candidates[] = new WaveOneCandidate(
                $this->integer($item, 'ordinal'),
                $this->string($item, 'group_id'),
                $this->string($item, 'code'),
                $this->string($item, 'family'),
                $this->string($item, 'source_status'),
                $this->string($item, 'publication'),
            );
        }

        return $candidates;
    }

    private function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (! is_int($value)) {
            throw new RuntimeException('wave_one_candidate_manifest_candidate_invalid');
        }

        return $value;
    }

    private function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (! is_string($value)) {
            throw new RuntimeException('wave_one_candidate_manifest_header_invalid');
        }

        return $value;
    }
}
