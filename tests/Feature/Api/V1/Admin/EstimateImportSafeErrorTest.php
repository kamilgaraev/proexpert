<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportErrorMessage;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportPipelineService;
use App\Jobs\ProcessEstimateImportJob;
use App\Models\ImportSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PDOException;
use RuntimeException;
use Tests\TestCase;

final class EstimateImportSafeErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_failure_is_logged_but_not_returned_to_client(): void
    {
        $session = $this->createImportSession();
        $exception = new QueryException('pgsql', 'insert into estimate_items values (?)', ['private-value'], new PDOException('SQLSTATE[22001]: value too long'));
        $pipeline = Mockery::mock(ImportPipelineService::class);
        $pipeline->shouldReceive('run')->once()->andThrow($exception);

        (new ProcessEstimateImportJob($session->id))->handle($pipeline);

        $session->refresh();
        self::assertSame('failed', $session->status);
        self::assertSame(trans_message('estimate.import_save_failed'), $session->error_message);
        self::assertArrayNotHasKey('technical_error_message', $session->stats);
        self::assertArrayNotHasKey('error_trace', $session->stats);
        $status = app(EstimateImportService::class)->getImportStatus($session->id);
        self::assertSame($session->error_message, $status['error']);
        self::assertSame($session->error_message, $status['message']);
        self::assertStringNotContainsString('private-value', json_encode($status));
    }

    public function test_legacy_errors_are_hidden_in_status_and_history(): void
    {
        $session = $this->createImportSession();
        $raw = 'SQLSTATE[22001]: insert into estimate_items values (private-value)';
        $session->update(['status' => 'failed', 'error_message' => $raw, 'stats' => ['message' => $raw, 'technical_error_message' => $raw, 'error_trace' => '/private/server/path']]);
        $service = app(EstimateImportService::class);
        $status = $service->getImportStatus($session->id);
        self::assertSame(trans_message('estimate.import_save_failed'), $status['error']);
        self::assertSame($status['error'], $status['message']);
        $history = $service->getImportHistory($session->organization_id)->toArray();
        self::assertSame($status['error'], $history[0]['error_message']);
        self::assertStringNotContainsString('SQLSTATE', json_encode($history));
        self::assertStringNotContainsString('private-value', json_encode($history));
        self::assertStringNotContainsString('/private/server/path', json_encode($history));
        self::assertSame($raw, $session->fresh()->error_message);
    }

    public function test_only_known_user_messages_are_preserved(): void
    {
        self::assertSame(trans_message('estimate.import_failed'), ImportErrorMessage::fromException(new RuntimeException('/private/path: unexpected exception')));
        self::assertSame(trans_message('estimate.import_failed'), ImportErrorMessage::fromStored('estimate.unknown_error'));
        self::assertSame(trans_message('estimate.import_unsupported_format'), ImportErrorMessage::fromStored('estimate.import_unsupported_format'));
        self::assertSame(trans_message('estimate.import_unsupported_format'), ImportErrorMessage::fromStored(trans_message('estimate.import_unsupported_format')));
        self::assertNull(ImportErrorMessage::fromStored(null));
    }

    private function createImportSession(): ImportSession
    {
        return ImportSession::query()->create([
            'user_id' => User::factory()->create()->id,
            'organization_id' => Organization::factory()->create()->id,
            'status' => 'queued',
            'file_name' => 'estimate.xlsx',
            'file_format' => 'xlsx',
            'file_size' => 100,
            'options' => [],
            'stats' => [],
        ]);
    }
}
