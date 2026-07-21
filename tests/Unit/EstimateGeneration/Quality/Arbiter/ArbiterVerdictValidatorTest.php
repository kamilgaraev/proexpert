<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdictValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArbiterVerdictValidatorTest extends TestCase
{
    #[Test]
    public function it_rejects_an_unknown_package_and_falls_back_to_human_review(): void
    {
        $verdict = (new ArbiterVerdictValidator)->validate([
            'outcome' => 'targeted_rebuild',
            'findings' => [[
                'scope_key' => 'heating',
                'package_keys' => ['invented-package'],
                'evidence_refs' => ['evidence:1'],
                'action' => 'rebuild',
                'reason_code' => 'missing_component',
            ]],
        ], $this->context());

        self::assertSame('human_review', $verdict->outcome);
        self::assertSame('invalid_reference', $verdict->findings[0]['reason_code']);
    }

    #[Test]
    public function it_strips_free_text_from_a_valid_verdict(): void
    {
        $verdict = (new ArbiterVerdictValidator)->validate([
            'outcome' => 'confirmed_scope_only',
            'findings' => [[
                'scope_key' => 'heating',
                'package_keys' => ['heating'],
                'evidence_refs' => ['evidence:1'],
                'action' => 'review',
                'reason_code' => 'evidence_required',
                'reason' => 'Text from a source document must not be persisted.',
            ]],
        ], $this->context());

        self::assertSame('confirmed_scope_only', $verdict->outcome);
        self::assertSame('evidence_required', $verdict->findings[0]['reason_code']);
        self::assertArrayNotHasKey('reason', $verdict->findings[0]);
    }

    #[Test]
    public function it_rejects_a_rebuild_without_an_existing_package_for_the_scope(): void
    {
        $verdict = (new ArbiterVerdictValidator)->validate([
            'outcome' => 'targeted_rebuild',
            'findings' => [[
                'scope_key' => 'heating',
                'package_keys' => [],
                'evidence_refs' => ['evidence:1'],
                'action' => 'rebuild',
                'reason_code' => 'missing_component',
            ]],
        ], [
            'scope_keys' => ['heating'],
            'package_keys' => [],
            'scope_packages' => ['heating' => []],
            'evidence_refs' => ['evidence:1'],
        ]);

        self::assertSame('human_review', $verdict->outcome);
        self::assertSame('invalid_reference', $verdict->findings[0]['reason_code']);
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return [
            'scope_keys' => ['heating'],
            'package_keys' => ['heating'],
            'scope_packages' => ['heating' => ['heating']],
            'evidence_refs' => ['evidence:1'],
        ];
    }
}
