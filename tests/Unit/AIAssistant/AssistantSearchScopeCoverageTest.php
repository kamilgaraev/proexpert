<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainDefinition;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainSearchCountEvidence;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSearchScopeCoverage;
use App\Models\User;
use PHPUnit\Framework\TestCase;

final class AssistantSearchScopeCoverageTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_every_search_specification_requires_organization_and_all_projects_including_zero_counts(): void
    {
        $organization = $this->attempt(null);
        $first = $this->attempt(52);
        $second = $this->attempt(100);
        foreach ([[], [$first], [$first, $first], [$organization], [$organization, $first]] as $attempts) {
            self::assertNull($this->references($attempts));
        }
        self::assertCount(3, $this->references([$first, $second, $organization]));
        self::assertCount(3, $this->references([$first, $second, $organization, $first]));
        self::assertSame(0, $organization['evidence']['value']);
    }

    public function test_different_queries_entities_domains_predicates_and_fields_do_not_complete_each_other(): void
    {
        foreach ([['query' => 'other'], ['domain' => 'contracts', 'entity_type' => 'contract'],
            ['entity_type' => 'other_act'], ['fields' => ['id']]] as $changes) {
            self::assertNull($this->references([$this->attempt(null, $changes), $this->attempt(52), $this->attempt(100)]));
        }
        $schedule = ['domain' => 'schedule', 'entity_type' => 'schedule_task'];
        self::assertNull($this->references([$this->attempt(null, $schedule + ['predicate' => 'overdue']),
            $this->attempt(52, $schedule), $this->attempt(100, $schedule)]));
    }

    public function test_untrusted_stale_and_other_actor_or_organization_receipts_cannot_cover_an_attempt(): void
    {
        $valid = $this->attempt(null);
        $forged = $valid;
        $forged['evidence']['source_ref']['_search_count_proof'] = str_repeat('0', 64);
        foreach ([$forged, $this->attempt(null, [], 3, 1, '-11 minutes'), $this->attempt(null, [], 9),
            $this->attempt(null, [], 3, 9)] as $untrusted) {
            self::assertNull($this->references([$untrusted, $this->attempt(52), $this->attempt(100)]));
        }
        foreach ([['project_id' => 52], ['query' => 'other'], ['fields' => ['id']]] as $changes) {
            $mismatch = $valid;
            $mismatch['arguments'] = array_replace($mismatch['arguments'], $changes);
            self::assertNull($this->references([$mismatch, $this->attempt(52), $this->attempt(100)]));
        }
    }

    public function test_failed_target_search_cannot_disappear_behind_an_unrelated_complete_comparison(): void
    {
        $failed = $this->attempt(52, ['query' => 'target']);
        $failed['evidence'] = null;
        $complete = [$this->attempt(null), $this->attempt(52), $this->attempt(100)];
        self::assertNull($this->references([$failed, ...$complete]));
        $retry = $this->attempt(52);
        $retry['evidence'] = null;
        self::assertCount(3, $this->references([$retry, ...$complete]));
    }

    public function test_checked_fields_determine_comparability_and_pagination_does_not_change_scope(): void
    {
        $organization = $this->attempt(null);
        $organization['arguments']['fields'] = null;
        $first = $this->attempt(52, ['fields' => ['status', 'id'], 'limit' => 1, 'cursor' => 'next']);
        self::assertCount(3, $this->references([$organization, $first, $this->attempt(100)]));
        self::assertNull($this->references([$organization, $this->attempt(52, ['fields' => ['id']]), $this->attempt(100)]));
    }

    public function test_independent_sections_cover_each_scope_with_different_search_specifications(): void
    {
        $subjects = array_fill_keys(['["organization","acts","performance_act"]', '["project:52","contracts","contract"]', '["project:100","acts","performance_act"]'], true);
        $attempts = [$this->attempt(null), $this->attempt(52, ['domain' => 'contracts', 'entity_type' => 'contract'], time: '-1 minute'),
            $this->attempt(100, ['query' => 'other', 'fields' => ['id']])];
        self::assertNull($this->references($attempts));
        self::assertCount(3, $this->references($attempts, false, $subjects));
        foreach ($attempts as $attempt) {
            self::assertSame(0, $attempt['evidence']['value']);
        }
        foreach ([[], [$attempts[0]], [$attempts[1], $attempts[2]], [$attempts[0], $attempts[1]]] as $incomplete) {
            self::assertNull($this->references($incomplete, false, $subjects));
        }
    }

    public function test_independent_sections_still_require_a_trusted_receipt_for_every_attempt(): void
    {
        $valid = $this->attempt(null);
        $forged = $valid;
        $forged['evidence']['source_ref']['_search_count_proof'] = str_repeat('0', 64);
        foreach ([$forged, $this->attempt(null, [], 3, 1, '-11 minutes'), $this->attempt(null, [], 9),
            $this->attempt(null, [], 3, 9)] as $untrusted) {
            self::assertNull($this->references([$untrusted, $this->attempt(52), $this->attempt(100)], false));
        }
        foreach ([['project_id' => 52], ['query' => 'other'], ['fields' => ['id']]] as $changes) {
            $mismatch = $valid;
            $mismatch['arguments'] = array_replace($mismatch['arguments'], $changes);
            self::assertNull($this->references([$mismatch, $this->attempt(52), $this->attempt(100)], false));
        }
        $complete = [$valid, $this->attempt(52), $this->attempt(100)];
        $failed = $this->attempt(52, ['query' => 'target']);
        $failed['evidence'] = null;
        self::assertNull($this->references([$failed, ...$complete], false));
        $failed['arguments']['query'] = '';
        self::assertCount(3, $this->references([$failed, ...$complete], false));
    }

    public function test_independent_subjects_cannot_be_swapped_or_replaced_by_another_entity(): void
    {
        $subjects = array_fill_keys(['["organization","acts","performance_act"]', '["project:52","contracts","contract"]', '["project:100","contracts","contract"]'], true);
        $contract = ['domain' => 'contracts', 'entity_type' => 'contract'];
        self::assertCount(3, $this->references([$this->attempt(null), $this->attempt(52, $contract), $this->attempt(100, $contract)], false, $subjects));
        foreach ([[$this->attempt(null, $contract), $this->attempt(52), $this->attempt(100)],
            [$this->attempt(null), $this->attempt(52, $contract), $this->attempt(100)],
            [$this->attempt(null), $this->attempt(52, $contract), $this->attempt(100, ['domain' => 'contracts', 'entity_type' => 'other_contract'])]] as $attempts) {
            self::assertNull($this->references($attempts, false, $subjects));
        }
        $actor = new User;
        $actor->setAttribute('id', 3);
        self::assertNull(AssistantSearchScopeCoverage::references([$this->attempt(null), $this->attempt(52), $this->attempt(100)], [52, 100], $actor, 1, false));
    }

    private function references(array $attempts, bool $pairwise = true, array $subjects = []): ?array
    {
        $actor = new User;
        $actor->setAttribute('id', 3);

        if (! $pairwise && $subjects === []) {
            $subjects = array_fill_keys(['["organization","acts","performance_act"]', '["project:52","acts","performance_act"]', '["project:100","acts","performance_act"]'], true);
        }

        return AssistantSearchScopeCoverage::references($attempts, [52, 100], $actor, 1, $pairwise, $subjects);
    }

    private function attempt(?int $projectId, array $changes = [], int $actorId = 3, int $organizationId = 1, string $time = 'now'): array
    {
        config(['app.key' => 'scope-coverage-test-key']);
        $arguments = array_replace(['domain' => 'acts', 'entity_type' => 'performance_act', 'query' => '', 'project_id' => $projectId,
            'fields' => ['id', 'status'], 'predicate' => null], $changes);
        $actor = new User;
        $actor->setAttribute('id', $actorId);
        $definition = new AssistantDomainDefinition($arguments['domain'], 'contract-management', $arguments['entity_type'],
            [], ['id', 'status'], [], ['search' => []], '/contracts', $arguments['domain'], [$arguments['entity_type']]);

        return ['arguments' => $arguments, 'evidence' => AssistantDomainSearchCountEvidence::payload($actor, $organizationId,
            $definition, $arguments['entity_type'], $arguments, $arguments['fields'], 0, (new \DateTimeImmutable($time))->format(DATE_ATOM))];
    }
}
