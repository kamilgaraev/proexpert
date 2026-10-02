<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Models\RagChunk;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceRegistry;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use Tests\Unit\AIAssistant\UsesAssistantUnitTranslations;

final class RagCoverageIdentityTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_projection_uses_indexer_checksum_normalization_and_exact_part_identity(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDriverName')->willReturn('sqlite');
        $connection->expects(self::never())->method('select');
        $database = $this->createMock(DatabaseManager::class);
        $database->method('connection')->willReturn($connection);
        DB::swap($database);
        $provider = $this->createMock(RagEmbeddingProviderInterface::class);
        $provider->method('provider')->willReturn('fake');
        $provider->method('model')->willReturn('fake-model');
        $provider->method('dimensions')->willReturn(2);
        $provider->expects(self::never())->method('embed');
        $indexer = new RagIndexer($provider, new RagSourceRegistry([]));
        $timestamp = new DateTimeImmutable('2026-09-29T12:00:00+03:00');
        $chunk = new RagChunkData(7, null, 'project', 'project', 42, " Title \r\n", " Body  text\r\n",
            ['z' => 2, 'unit_id' => 0, 'a' => 'value'], $timestamp);
        $identity = $indexer->coverageIdentity($chunk);
        $this->assertSame(0, $identity['identity_project_id']);
        $this->assertSame('0', $identity['identity_part_key']);
        $this->assertSame('42', $identity['entity_id']);
        $this->assertSame(7, $identity['organization_id']);
        $this->assertArrayNotHasKey('title', $identity);
        $this->assertArrayNotHasKey('content', $identity);
        $this->assertArrayNotHasKey('metadata', $identity);
        $stored = new RagChunk;
        $stored->setRawAttributes([
            'chunk_index' => 0,
            'content' => 'Body text',
            'content_hash' => hash('sha256', 'Body text'),
            'embedding_provider' => 'fake',
            'embedding_model' => 'fake-model',
            'embedding' => '[0.1,0.2]',
        ]);
        $wrongProvider = clone $stored;
        $wrongProvider->setAttribute('embedding_provider', 'other-provider');
        $wrongModel = clone $stored;
        $wrongModel->setAttribute('embedding_model', 'other-model');
        $wrongDimensions = clone $stored;
        $wrongDimensions->setAttribute('embedding', '[0.1]');
        $chunks = $this->createMock(HasMany::class);
        $chunks->expects(self::exactly(10))->method('get')->willReturnOnConsecutiveCalls(
            new Collection([$stored]), new Collection([$stored]),
            new Collection([$stored]), new Collection([$wrongProvider]),
            new Collection([$stored]), new Collection([$wrongModel]),
            new Collection([$stored]), new Collection([$wrongDimensions]),
            new Collection([$stored]), new Collection,
        );
        $source = $this->getMockBuilder(RagSource::class)->onlyMethods(['chunks'])->getMock();
        $source->setRawAttributes(['checksum' => $identity['checksum']]);
        $source->expects(self::exactly(10))->method('chunks')->willReturn($chunks);
        $equivalent = new RagChunkData(7, null, 'project', 'project', '42', 'Title', 'Body text',
            ['a' => 'value', 'unit_id' => 0, 'z' => 2], $timestamp);
        $this->assertTrue($indexer->matchesSource($source, $equivalent));
        $changed = new RagChunkData(7, null, 'project', 'project', '42', 'Title', 'Changed',
            ['a' => 'value', 'unit_id' => 0, 'z' => 2], $timestamp);
        $this->assertFalse($indexer->matchesSource($source, $changed));
        $this->assertFalse($indexer->matchesSource($source, $equivalent), 'Provider mismatch invalidates otherwise identical content');
        $this->assertFalse($indexer->matchesSource($source, $equivalent), 'Model mismatch invalidates otherwise identical content');
        $this->assertFalse($indexer->matchesSource($source, $equivalent), 'Dimension mismatch invalidates otherwise identical content');
        $this->assertFalse($indexer->matchesSource($source, $equivalent), 'Content checksum alone cannot prove indexed embeddings');
    }
}
