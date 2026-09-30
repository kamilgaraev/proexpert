<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantSourceReferenceIdentityTest extends TestCase
{
    public function test_associative_order_and_security_set_order_do_not_change_identity(): void
    {
        $first = $this->reference() + ['checked_fields' => ['amount', 'currency'], 'required_permissions' => ['finance.view', 'projects.view'], 'composite_key' => ['event_id' => 7, 'ordinal' => 1]];
        $second = array_reverse($first, true);
        $second['checked_fields'] = ['currency', 'amount', 'amount'];
        $second['required_permissions'] = ['projects.view', 'finance.view'];
        $second['composite_key'] = ['ordinal' => 1, 'event_id' => 7];
        $this->assertSame(AssistantSourceReferenceIdentity::key($first), AssistantSourceReferenceIdentity::key($second));
        $second['composite_key']['ordinal'] = '1';
        $this->assertNotSame(AssistantSourceReferenceIdentity::key($first), AssistantSourceReferenceIdentity::key($second));
    }

    public function test_main_and_conversation_preserve_each_child_permission_and_version_proof(): void
    {
        $base = $this->reference();
        $references = [
            $base + ['checked_fields' => ['amount'], 'required_permissions' => ['finance.view']],
            $base + ['checked_fields' => ['name'], 'required_permissions' => []],
            $base + ['projection_name' => 'award_candidates', 'composite_key' => ['ordinal' => 1]],
            $base + ['projection_name' => 'award_candidates', 'composite_key' => ['ordinal' => 2]],
            array_replace($base, ['source_version' => 'changed']),
        ];
        $main = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();
        $collected = (new ReflectionMethod($main, 'collectSourceRefs'))->invoke($main, [], [['source_refs' => array_merge($references, [$references[0]])]]);
        $this->assertSame($references, $collected);
        $manager = (new ReflectionClass(ConversationManager::class))->newInstanceWithoutConstructor();
        $merged = (new ReflectionMethod($manager, 'mergeReferences'))->invoke($manager, array_slice($references, 0, 2), array_slice($references, 2));
        $this->assertSame($references, $merged);
        $summary = (new ReflectionMethod($manager, 'boundedSummary'))->invoke($manager, [['text' => 'Amount and candidate answers', 'source_refs' => $references]], [], []);
        $this->assertSame($references, $summary['source_refs']);
        $this->assertSame('Amount and candidate answers', $summary['summary']);
        $selected = (new ReflectionMethod($manager, 'boundedSummary'))->invoke($manager, [], [['entity_type' => 'project', 'entity_id' => 7]], $references);
        $this->assertSame($references, $selected['source_refs']);
    }

    public function test_summary_drops_the_whole_text_when_its_proofs_exceed_the_bound(): void
    {
        $references = [];
        for ($ordinal = 0; $ordinal < 101; $ordinal++) {
            $references[] = $this->reference() + ['projection_name' => 'award_candidates', 'composite_key' => ['ordinal' => $ordinal]];
        }
        $manager = (new ReflectionClass(ConversationManager::class))->newInstanceWithoutConstructor();
        $summary = (new ReflectionMethod($manager, 'boundedSummary'))->invoke($manager, [['text' => 'Answer requiring every child', 'source_refs' => $references]], [], []);
        $this->assertSame('', $summary['summary']);
        $this->assertSame([], $summary['source_refs']);
        $this->assertSame([], $summary['segments']);
    }

    private function reference(): array
    {
        return ['entity_type' => 'project', 'entity_id' => 7, 'content_scope' => 'structured', 'source_version' => 'original', 'fetched_at' => '2026-09-29T12:00:00.000000Z'];
    }
}
