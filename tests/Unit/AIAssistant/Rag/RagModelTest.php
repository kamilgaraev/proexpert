<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use PHPUnit\Framework\TestCase;

class RagModelTest extends TestCase
{
    public function test_source_model_contract(): void
    {
        $source = new RagSource();

        $this->assertSame('ai_rag_sources', $source->getTable());
        $this->assertContains('metadata', array_keys($source->getCasts()));
        $this->assertContains('indexed_at', array_keys($source->getCasts()));
    }

    public function test_chunk_model_contract(): void
    {
        $chunk = new RagChunk();

        $this->assertSame('ai_rag_chunks', $chunk->getTable());
        $this->assertContains('metadata', array_keys($chunk->getCasts()));
        $this->assertContains('embedding_created_at', array_keys($chunk->getCasts()));
    }
}
