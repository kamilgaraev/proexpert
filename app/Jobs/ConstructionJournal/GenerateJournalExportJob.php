<?php

declare(strict_types=1);

namespace App\Jobs\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\JournalExport;
use Carbon\Carbon;
use DomainException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateJournalExportJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public int $uniqueFor = 1800;

    public function __construct(public readonly string $exportId) {}

    public function uniqueId(): string
    {
        return $this->exportId;
    }

    public function handle(OfficialFormsExportService $files): void
    {
        $export = JournalExport::query()->with(['journal.project.organization', 'entry.journal.project.organization'])
            ->findOrFail($this->exportId);

        if ($export->status === JournalExport::STATUS_COMPLETED) {
            return;
        }

        $export->update([
            'status' => JournalExport::STATUS_PROCESSING,
            'progress' => 10,
            'started_at' => now(),
            'error_code' => null,
        ]);

        try {
            $options = $export->options;
            if ($export->type === 'daily' && $export->entry === null) {
                throw new \DomainException('journal_export_entry_missing');
            }
            $path = match ($export->type) {
                'ks6' => $export->format === 'pdf'
                    ? $files->exportKS6ToPdf($export->journal, Carbon::parse($options['date_from']), Carbon::parse($options['date_to']), $options['estimate_id'] ?? null, $export->id)
                    : $files->exportKS6ToExcel($export->journal, Carbon::parse($options['date_from']), Carbon::parse($options['date_to']), $options['estimate_id'] ?? null, $export->id),
                'extended' => $export->format === 'pdf'
                    ? $files->exportExtendedReportToPdf($export->journal, $options, $export->id)
                    : $files->exportExtendedReportToExcel($export->journal, $options, $export->id),
                'daily' => $files->exportDailyReportToPdf($export->entry, $export->id),
                default => throw new \DomainException('unsupported_export_type'),
            };

            $export->update([
                'status' => JournalExport::STATUS_COMPLETED,
                'progress' => 100,
                'result_path' => $path,
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('construction_journal.export_generation_failed', [
                'export_id' => $export->id,
                'journal_id' => $export->journal_id,
                'exception_class' => $exception::class,
            ]);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        JournalExport::query()->whereKey($this->exportId)->update([
            'status' => JournalExport::STATUS_FAILED,
            'error_code' => $exception instanceof DomainException
                && in_array($exception->getMessage(), [
                    trans_message('construction_journal.errors.pdf_export_too_large'),
                    trans_message('construction_journal.errors.spreadsheet_export_too_large'),
                ], true)
                    ? 'export_too_large'
                    : 'generation_failed',
            'completed_at' => now(),
        ]);
    }
}
