<?php

declare(strict_types=1);

namespace Tests\Support\Reporting\Quality;

use App\BusinessModules\Core\Reporting\Application\Quality\ReportPlatformGateCatalog;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final class ReportPlatformGateFixtureBuilder
{
    public function __construct(
        private readonly string $repositoryRoot,
        private readonly string $releaseSha = '1111111111111111111111111111111111111111',
        private readonly string $generatedAt = '2026-07-26T00:00:00Z',
    ) {
    }

    public function bytes(): string
    {
        return CanonicalJson::encode($this->build())."\n";
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $catalogPath = $this->repositoryRoot.'/docs/reports/contracts/report-platform-gates.v1.json';
        $catalog = new ReportPlatformGateCatalog($catalogPath);
        $gates = [];

        foreach ($catalog->records() as $definition) {
            $sources = array_map(fn (string $path): array => ['path' => $path, 'sha256' => $this->hash($path)], $definition['source_paths']);
            $gates[] = [
                'gate' => $definition['id'],
                'owner_plan' => $definition['release_owner'],
                'phase' => 'platform',
                'status' => $definition['platform_status'],
                'command' => $definition['command'],
                'count' => $definition['minimum_count'],
                'schema_sha256' => $definition['schema_sha256'],
                'release_sha' => $this->releaseSha,
                'commit_sha' => $this->releaseSha,
                'executed_at' => $this->generatedAt,
                'artifact_sha256' => $definition['platform_status'] === 'passed' ? hash('sha256', CanonicalJson::encode($sources)) : null,
                'source_artifacts' => $sources,
            ];
        }

        return ['artifact_id' => 'report_platform_gate_inputs', 'schema_version' => '1.0.0', 'status' => 'platform_gate_inputs_passed', 'catalog' => ['path' => 'docs/reports/contracts/report-platform-gates.v1.json', 'sha256' => $this->hash('docs/reports/contracts/report-platform-gates.v1.json')], 'release_sha' => $this->releaseSha, 'generated_at' => $this->generatedAt, 'gates' => $gates];
    }

    private function hash(string $relativePath): string
    {
        $bytes = file_get_contents($this->repositoryRoot.'/'.$relativePath);

        if (! is_string($bytes)) {
            throw new \RuntimeException('report_platform_gate_fixture_source_missing');
        }

        return hash('sha256', $bytes);
    }
}

