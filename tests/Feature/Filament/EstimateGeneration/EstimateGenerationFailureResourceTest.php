<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\EstimateGeneration;

use App\Filament\Resources\EstimateGeneration\FailureResource;
use App\Filament\Support\EstimateGeneration\FailureDiagnosticsPresenter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class EstimateGenerationFailureResourceTest extends TestCase
{
    public function test_failure_query_selects_only_closed_diagnostics_projection(): void
    {
        self::assertSame([
            'id', 'organization_id', 'project_id', 'session_id', 'document_id', 'page_id',
            'unit_id', 'checkpoint_id', 'usage_attempt_id', 'stage', 'operation', 'provider',
            'model', 'category', 'code', 'attempt', 'occurrence_count', 'first_seen_at',
            'last_seen_at', 'resolved_at', 'resolution_code', 'latest_occurrence_sequence',
        ], $this->safeColumns());

        $source = $this->source();
        self::assertStringContainsString("->with('session:id,organization_id,project_id,status')", $source);
        self::assertStringContainsString("safe_context->>'{\$key}' as diagnostic_{\$key}", $source);
        self::assertStringContainsString("->defaultSort('last_seen_at', 'desc')", $source);
        self::assertStringContainsString('paginationPageOptions([25, 50, 100])', $source);
        foreach (['period', 'organization_id', 'stage', 'category', 'resolved_at'] as $filter) {
            self::assertMatchesRegularExpression("/(?:Filter|SelectFilter|TernaryFilter)::make\\('{$filter}'\\)/", $source);
        }
    }

    public function test_closed_presenter_discards_sensitive_and_unknown_keys(): void
    {
        $diagnostics = FailureDiagnosticsPresenter::present([
            'Authorization' => 'Bearer eyJmalicious.payload.signature',
            'api_key' => 'sk-production-secret',
            'prompt' => 'confidential estimate prompt',
            'response_body' => ['raw' => 'provider answer'],
            'stack_trace' => 'C:\\app\\provider.php:52',
            'provider_code' => 'timeout',
            'http_class' => '5xx',
            'http_code' => 504,
            'attempt' => 3,
        ]);

        self::assertSame([
            'provider_code' => 'timeout',
            'http_class' => '5xx',
            'http_code' => '504',
            'attempt' => '3',
        ], $diagnostics);
    }

    public function test_failure_resource_is_strictly_read_only(): void
    {
        $source = $this->source();

        self::assertSame(4, FailureResource::getNavigationSort());
        self::assertStringContainsString('return NavigationGroups::aiEstimator();', $source);
        self::assertStringContainsString('FilamentPermission::ESTIMATE_GENERATION_MONITOR', $source);
        self::assertStringContainsString('public static function canCreate(): bool', $source);
        self::assertStringContainsString('public static function canEdit(Model $record): bool', $source);
        self::assertStringContainsString('public static function canDelete(Model $record): bool', $source);
        self::assertStringNotContainsString("Action::make('mark_resolved')", $source);
        self::assertStringNotContainsString('ESTIMATE_GENERATION_OPERATE', $source);
        self::assertStringNotContainsString('ResolveEstimateGenerationFailure', $source);
        self::assertStringNotContainsString('DeleteAction::make', $source);
        self::assertStringNotContainsString('BulkAction', $source);
    }

    private function source(): string
    {
        $source = file_get_contents((new ReflectionClass(FailureResource::class))->getFileName());
        self::assertIsString($source);

        return $source;
    }

    /** @return list<string> */
    private function safeColumns(): array
    {
        $columns = (new ReflectionClass(FailureResource::class))->getMethod('safeColumns')->invoke(null);
        self::assertIsArray($columns);

        return $columns;
    }
}
