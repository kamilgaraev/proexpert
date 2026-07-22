<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcher;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TargetedPackageDraftPatcherTest extends TestCase
{
    public function test_it_replaces_only_the_named_package_and_preserves_other_canonical_fingerprints(): void
    {
        $draft = $this->draftWith('foundation', 'heating', 'ventilation');
        $original = $draft;
        $replacement = $this->package('heating', 'revised-heating-work');

        $result = (new TargetedPackageDraftPatcher())->replace(
            $draft,
            $this->sourceInputVersion(),
            'heating',
            $replacement,
        );

        self::assertSame('revised-heating-work', $result->draft['local_estimates'][1]['sections'][0]['work_items'][0]['key']);
        self::assertSame('heating-work', $draft['local_estimates'][1]['sections'][0]['work_items'][0]['key']);
        self::assertSame($this->fingerprint($original['local_estimates'][0]), $result->nonTargetFingerprints['foundation']);
        self::assertSame($this->fingerprint($original['local_estimates'][2]), $result->nonTargetFingerprints['ventilation']);
        self::assertSame(['foundation', 'ventilation'], array_keys($result->nonTargetFingerprints));
        self::assertSame($this->fingerprint($original['local_estimates'][1]), $result->targetBeforeFingerprint);
        self::assertSame($this->fingerprint($replacement), $result->targetAfterFingerprint);

        $originalTopLevel = $original;
        unset($originalTopLevel['local_estimates']);
        $resultTopLevel = $result->draft;
        unset($resultTopLevel['local_estimates']);

        self::assertSame($originalTopLevel, $resultTopLevel);
    }

    public function test_it_rejects_a_stale_source_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TargetedPackageDraftPatcher())->replace(
            $this->draftWith('foundation', 'heating'),
            'sha256:'.str_repeat('b', 64),
            'heating',
            $this->package('heating', 'revised-heating-work'),
        );
    }

    public function test_it_rejects_a_duplicate_target_package_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TargetedPackageDraftPatcher())->replace(
            $this->draftWith('heating', 'heating'),
            $this->sourceInputVersion(),
            'heating',
            $this->package('heating', 'revised-heating-work'),
        );
    }

    public function test_it_rejects_an_unknown_target_package_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TargetedPackageDraftPatcher())->replace(
            $this->draftWith('foundation', 'heating'),
            $this->sourceInputVersion(),
            'ventilation',
            $this->package('ventilation', 'revised-ventilation-work'),
        );
    }

    public function test_it_rejects_a_replacement_with_a_different_package_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new TargetedPackageDraftPatcher())->replace(
            $this->draftWith('foundation', 'heating'),
            $this->sourceInputVersion(),
            'heating',
            $this->package('ventilation', 'revised-ventilation-work'),
        );
    }

    public function test_it_passes_the_replacement_byte_for_byte_without_adding_metadata(): void
    {
        $replacement = $this->package('heating', 'revised-heating-work');
        $replacement['sections'][0]['work_items'][0]['norm'] = [
            'code' => 'ФЕР 16-01-001-01',
            'resources' => [
                ['kind' => 'water', 'quantity' => 0.15],
                ['kind' => 'pipe', 'quantity' => 18],
                ['kind' => 'labor', 'quantity' => 4.5],
                ['kind' => 'machine', 'quantity' => 0.2],
                ['kind' => 'operator', 'quantity' => 0.2],
            ],
        ];

        $result = (new TargetedPackageDraftPatcher())->replace(
            $this->draftWith('foundation', 'heating', 'ventilation'),
            $this->sourceInputVersion(),
            'heating',
            $replacement,
        );

        self::assertSame($replacement, $result->draft['local_estimates'][1]);
    }

    private function draftWith(string ...$packageKeys): array
    {
        return [
            'source_input_version' => $this->sourceInputVersion(),
            'local_estimates' => array_map(
                fn (string $packageKey): array => $this->package($packageKey, $packageKey.'-work'),
                $packageKeys,
            ),
            'arbiter_review' => ['outcome' => 'confirmed_scope_only'],
            'budget_scope' => ['claim' => 'confirmed_scope_only'],
            'completeness' => ['status' => 'review_required'],
            'totals' => ['direct_costs' => 3154397.72],
            'readiness' => ['status' => 'review_required'],
        ];
    }

    private function package(string $key, string $workItemKey): array
    {
        return [
            'key' => $key,
            'sections' => [[
                'key' => $key.'-section',
                'work_items' => [[
                    'key' => $workItemKey,
                    'quantity' => 1.0,
                ]],
            ]],
        ];
    }

    private function sourceInputVersion(): string
    {
        return 'sha256:'.str_repeat('a', 64);
    }

    private function fingerprint(array $package): string
    {
        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($package));
    }
}
