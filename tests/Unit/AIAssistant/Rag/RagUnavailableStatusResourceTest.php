<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class RagUnavailableStatusResourceTest extends TestCase
{
    public function test_unavailable_status_preserves_unknown_counts_without_catalog_or_settings(): void
    {
        $result = (new RagIndexStatusResource(['status_available' => false, 'source_count' => null, 'chunk_count' => null]))->toArray(new Request);

        $this->assertFalse($result['status_available']);
        foreach (['source_count', 'chunk_count', 'expected_source_count', 'indexed_source_count', 'stored_source_count', 'pending_source_count',
            'stale_source_count', 'document_coverage', 'archive_scan', 'latest_run', 'last_successful_run', 'last_failed_run'] as $key) {
            $this->assertNull($result[$key], $key);
        }
        foreach (['ready', 'coverage_complete', 'eligible_count_known', 'can_reindex', 'can_manage_document_settings'] as $key) {
            $this->assertFalse($result[$key], $key);
        }
        $this->assertSame([], $result['source_catalog']);
    }

    public function test_successful_status_defaults_to_available_and_keeps_zero_counts(): void
    {
        $result = (new RagIndexStatusResource(['source_count' => 0, 'chunk_count' => 0]))->toArray(new Request);

        $this->assertTrue($result['status_available']);
        $this->assertSame(0, $result['source_count']);
        $this->assertSame(0, $result['chunk_count']);
    }

    public function test_source_catalog_preserves_only_valid_optional_display_labels(): void
    {
        $result = (new RagIndexStatusResource([
            'source_catalog' => [
                ['type' => 'known_source', 'display_label' => 'Проекты', 'expected_count' => 4, 'error' => null],
                ['type' => 'future_source', 'display_label' => ['invalid'], 'expected_count' => 2, 'error' => 'unavailable'],
                ['type' => 'unlabelled_source', 'expected_count' => 0],
            ],
        ]))->toArray(new Request);

        $this->assertSame([
            [
                'type' => 'known_source',
                'enabled' => true,
                'expected_count' => 4,
                'indexed_count' => null,
                'stored_count' => null,
                'stale_count' => null,
                'pending_count' => null,
                'error' => null,
                'display_label' => 'Проекты',
            ],
            [
                'type' => 'future_source',
                'enabled' => true,
                'expected_count' => 2,
                'indexed_count' => null,
                'stored_count' => null,
                'stale_count' => null,
                'pending_count' => null,
                'error' => 'unavailable',
            ],
            [
                'type' => 'unlabelled_source',
                'enabled' => true,
                'expected_count' => 0,
                'indexed_count' => null,
                'stored_count' => null,
                'stale_count' => null,
                'pending_count' => null,
                'error' => null,
            ],
        ], $result['source_catalog']);
    }
}
