<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Application\Understanding\ProjectUnderstandingInputFingerprint;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectUnderstandingExactSnapshotTokenTest extends TestCase
{
    #[Test]
    public function exact_token_is_permutation_stable_but_sensitive_to_every_semantic_identity(): void
    {
        $state = $this->state();
        $permuted = $state;
        $permuted['facts'] = array_reverse($permuted['facts']);
        $permuted['bindings'] = array_reverse($permuted['bindings']);
        $permuted['evidence'] = array_reverse($permuted['evidence']);

        $token = ProjectUnderstandingInputFingerprint::fromExactState($state);
        self::assertSame($token, ProjectUnderstandingInputFingerprint::fromExactState($permuted));

        foreach ([
            ['facts', 0, 'id', 99],
            ['facts', 0, 'value_hash', str_repeat('f', 64)],
            ['entities', 0, 'content_hash', str_repeat('d', 64)],
            ['bindings', 0, 'evidence_id', 99],
            ['evidence', 0, 'locator_hash', str_repeat('e', 64)],
            ['decisions', 0, 'version', 2],
            ['scope', null, 'organization_id', 9],
        ] as [$section, $index, $field, $value]) {
            $changed = $state;
            if ($index === null) {
                $changed[$section][$field] = $value;
            } else {
                $changed[$section][$index][$field] = $value;
            }
            self::assertNotSame($token, ProjectUnderstandingInputFingerprint::fromExactState($changed), $section.'.'.$field);
        }
    }

    private function state(): array
    {
        return [
            'scope' => ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3],
            'source_versions' => ['sha256:a', 'sha256:b'],
            'entities' => [['id' => 1, 'stable_key' => 'entity:a', 'source_version' => 'sha256:a', 'content_hash' => str_repeat('c', 64)]],
            'facts' => [
                ['id' => 2, 'stable_key' => 'fact:b', 'version' => 1, 'projection_version' => 1, 'status' => 'confirmed', 'value_hash' => str_repeat('b', 64)],
                ['id' => 1, 'stable_key' => 'fact:a', 'version' => 1, 'projection_version' => 1, 'status' => 'confirmed', 'value_hash' => str_repeat('a', 64)],
            ],
            'bindings' => [
                ['fact_id' => 2, 'evidence_id' => 2, 'source_version' => 'sha256:a', 'evidence_source_version' => 'sha256:b', 'evidence_invalidation_version' => 1],
                ['fact_id' => 1, 'evidence_id' => 1, 'source_version' => 'sha256:a', 'evidence_source_version' => 'sha256:b', 'evidence_invalidation_version' => 1],
            ],
            'evidence' => [
                ['id' => 2, 'source_version' => 'sha256:b', 'invalidation_version' => 1, 'locator_hash' => str_repeat('d', 64)],
                ['id' => 1, 'source_version' => 'sha256:b', 'invalidation_version' => 1, 'locator_hash' => str_repeat('c', 64)],
            ],
            'decisions' => [['id' => 1, 'stable_key' => 'decision:1', 'selected_fact_id' => 'fact:a', 'version' => 1]],
        ];
    }
}
