<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Jobs\IndexGlobalRagEntityJob;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class GlobalRagQueueTest extends TestCase
{
    public function test_only_registered_global_entity_pairs_and_canonical_positive_ids_are_accepted(): void
    {
        foreach ([['knowledge', 'knowledge_article'], ['estimate_reference', 'estimate_template'], ['estimate_reference', 'estimate_library_item'],
            ['estimate_reference', 'estimate_catalog_item'], ['estimate_reference', 'normative_rate']] as [$source, $entity]) {
            $this->assertTrue(GlobalRagQueue::supports($source, $entity, 12));
        }
        foreach ([0, -1, '01', '+1', ' 1', '1 OR 1=1', '99999999999999999999999999'] as $id) {
            $this->assertFalse(GlobalRagQueue::supports('knowledge', 'knowledge_article', $id));
        }
        $this->assertFalse(GlobalRagQueue::supports('knowledge', 'estimate_template', 12));
        $this->assertFalse(GlobalRagQueue::supports('project', 'project', 12));
        $this->assertFalse(GlobalRagQueue::supports('file_document', 'assistant_document', 12));
    }

    public function test_old_serialized_global_job_initializes_optional_event_identity_and_keeps_legacy_cursor(): void
    {
        $legacy = (new ReflectionClass(IndexGlobalRagEntityJob::class))->newInstanceWithoutConstructor();
        $legacy->sourceType = 'knowledge';
        $legacy->entityType = 'knowledge_article';
        $legacy->entityId = 12;
        $legacy->afterOrganizationId = 50;
        unset($legacy->eventId, $legacy->eventRevision);
        $restored = unserialize(serialize($legacy), ['allowed_classes' => [IndexGlobalRagEntityJob::class]]);
        $this->assertInstanceOf(IndexGlobalRagEntityJob::class, $restored);
        $this->assertNull($restored->eventId);
        $this->assertNull($restored->eventRevision);
        $this->assertSame(50, $restored->afterOrganizationId);
        $this->assertSame('knowledge_article', $restored->entityType);
    }
}
