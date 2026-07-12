<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Benchmark;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeRerankResultData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use JsonException;
use Throwable;

final readonly class RecordedPortEnvelopeLoader
{
    public function __construct(private string $fixtureRoot, private string $manifestPath) {}

    public function load(BenchmarkCaseData $case, string $benchmarkManifestSha256): RecordedPortEnvelopeSet
    {
        return $this->loadFor($case->id, $case->inputSha256, $benchmarkManifestSha256, null, null);
    }

    public function loadProjection(BenchmarkPredictionCaseData $case): RecordedPortEnvelopeSet
    {
        if ($case->recordingManifestSha256 === null || $case->recordedEnvelopeReferences === []) {
            throw new RecordedPortEnvelopeException('recorded_projection_incomplete');
        }

        return $this->loadFor(
            $case->id,
            $case->inputSha256,
            $case->recordingManifestSha256,
            $case->recordedEnvelopeReferences,
            $case->recordedEnvelopeSha256,
        );
    }

    /** @param array<string, string>|null $references @param array<string, string>|null $hashes */
    private function loadFor(string $caseId, string $inputSha256, string $recordingManifestSha256, ?array $references, ?array $hashes): RecordedPortEnvelopeSet
    {
        $root = realpath($this->fixtureRoot);
        $manifest = realpath($this->manifestPath);
        if ($root === false || $manifest === false || ! $this->within($manifest, $root) || is_link($this->manifestPath)) {
            throw new RecordedPortEnvelopeException('recorded_manifest_path_invalid');
        }
        try {
            $data = json_decode((string) file_get_contents($manifest), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RecordedPortEnvelopeException('recorded_manifest_json_invalid');
        }
        if (! is_array($data) || array_keys($data) !== ['schema_version', 'fixtures']
            || $data['schema_version'] !== 1 || ! is_array($data['fixtures']) || ! array_is_list($data['fixtures'])) {
            throw new RecordedPortEnvelopeException('recorded_manifest_contract_invalid');
        }
        $envelopes = [];
        foreach ($data['fixtures'] as $descriptor) {
            if (! is_array($descriptor) || array_keys($descriptor) !== ['case_id', 'port', 'locator', 'sha256']) {
                throw new RecordedPortEnvelopeException('recorded_descriptor_contract_invalid');
            }
            if ($descriptor['case_id'] !== $caseId) {
                continue;
            }
            if (! is_string($descriptor['locator']) || ! preg_match('#^[a-zA-Z0-9._/-]+\.json$#D', $descriptor['locator'])
                || str_contains($descriptor['locator'], '..') || ! is_string($descriptor['sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $descriptor['sha256']) !== 1) {
                throw new RecordedPortEnvelopeException('recorded_descriptor_invalid');
            }
            $path = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $descriptor['locator']));
            if ($path === false || ! $this->within($path, $root) || is_link($path)
                || ! hash_equals($descriptor['sha256'], (string) hash_file('sha256', $path))) {
                throw new RecordedPortEnvelopeException('recorded_fixture_integrity_failed');
            }
            $envelope = RecordedPortEnvelope::fromFile($path);
            if ($references !== null && (($references[$descriptor['port']] ?? null) !== $descriptor['locator']
                || ($hashes[$descriptor['port']] ?? null) !== $descriptor['sha256'])) {
                throw new RecordedPortEnvelopeException('recorded_projection_descriptor_mismatch');
            }
            if ($envelope->port->value !== $descriptor['port'] || ! hash_equals($inputSha256, $envelope->sourceSha256)
                || ! hash_equals($recordingManifestSha256, $envelope->manifestSha256)) {
                throw new RecordedPortEnvelopeException('recorded_dependency_mismatch');
            }
            if (isset($envelopes[$envelope->port->value])) {
                throw new RecordedPortEnvelopeException('recorded_port_duplicate');
            }
            $this->validatePayload($envelope);
            $envelopes[$envelope->port->value] = $envelope;
        }
        ksort($envelopes, SORT_STRING);
        if ($references !== null && count($envelopes) !== count($references)) {
            throw new RecordedPortEnvelopeException('recorded_projection_descriptor_missing');
        }
        return new RecordedPortEnvelopeSet($envelopes);
    }

    private function validatePayload(RecordedPortEnvelope $envelope): void
    {
        try {
            match ($envelope->port) {
                RecordedPort::VisionExtraction => VisionAnalysisData::fromProviderArray(
                    $envelope->payload,
                    $envelope->provider,
                    $envelope->modelVersion,
                    $envelope->modelVersion,
                    $envelope->payloadSchemaVersion,
                    'unavailable',
                    null,
                    null,
                    500,
                ),
                RecordedPort::DocumentExtraction, RecordedPort::CadExtraction => VectorGeometryData::fromArray($envelope->payload),
                RecordedPort::GeometryConfirmation => GeometryConfirmationData::fromArray($envelope->payload),
                RecordedPort::NormativeReranker => $this->validateRerankerPayload($envelope->payload),
                RecordedPort::WorkPlanningModel => RecordedWorkPlannerResponseData::fromProviderArray($envelope->payload),
            };
        } catch (Throwable) {
            throw new RecordedPortEnvelopeException('recorded_port_payload_invalid');
        }
    }

    /** @param array<string, mixed> $payload */
    private function validateRerankerPayload(array $payload): NormativeRerankResultData
    {
        $ordering = $payload['ordering'] ?? null;

        return NormativeRerankResultData::fromProviderArray(
            $payload,
            is_array($ordering) && array_is_list($ordering) ? $ordering : [],
        );
    }

    private function within(string $path, string $root): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/').'/';

        return str_starts_with(str_replace('\\', '/', $path), $root);
    }
}
