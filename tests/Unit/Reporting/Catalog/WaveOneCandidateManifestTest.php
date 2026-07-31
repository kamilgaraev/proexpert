<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Catalog;

use App\BusinessModules\Core\Reporting\Domain\DTO\WaveOneCandidate;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlWaveOneCandidateManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;

final class WaveOneCandidateManifestTest extends TestCase
{
    public function test_production_manifest_loads_the_closed_candidate_identity_set_in_literal_order(): void
    {
        $manifest = $this->loader()->load(
            $this->resource('candidates/wave-1-candidates.v1.yaml'),
            $this->resource('candidates/wave-1-candidates.v1.schema.json'),
        );

        self::assertSame(
            ['G01', 'G04', 'G06', 'G09', 'G10', 'G11', 'G12', 'G13', 'G21', 'G22', 'G23', 'G24'],
            array_map(static fn (WaveOneCandidate $item): string => $item->groupId, $manifest->ordered()),
        );
        self::assertSame(
            [
                'implemented', 'implemented', 'source contract required', 'implemented',
                'implemented', 'source contract required', 'source/formula contract required',
                'source/formula contract required', 'source contract required', 'source contract required',
                'source contract required', 'source contract required',
            ],
            array_map(static fn (WaveOneCandidate $item): string => $item->sourceStatus, $manifest->ordered()),
        );
        self::assertSame('candidate', $manifest->ordered()[0]->publication);
    }

    private function loader(): YamlWaveOneCandidateManifestLoader
    {
        return new YamlWaveOneCandidateManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
        );
    }

    private function resource(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }
}
