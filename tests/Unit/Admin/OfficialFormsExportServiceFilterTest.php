<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\ConstructionJournal;
use App\Services\Storage\FileService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OfficialFormsExportServiceFilterTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    public function test_journal_export_query_filters_entries_by_estimate(): void
    {
        $journalId = $this->createJournal();
        $firstEntryId = $this->createJournalEntry($journalId, 10, 'approved');
        $this->createJournalEntry($journalId, 20, 'approved');

        $service = new TestableOfficialFormsExportService(Mockery::mock(FileService::class));

        $entryIds = $service->entryIds(
            ConstructionJournal::query()->findOrFail($journalId),
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            10
        );

        $this->assertSame([$firstEntryId], $entryIds);
    }

    public function test_journal_export_query_can_limit_entries_to_approved_status(): void
    {
        $journalId = $this->createJournal();
        $approvedEntryId = $this->createJournalEntry($journalId, 10, 'approved');
        $this->createJournalEntry($journalId, 10, 'draft');

        $service = new TestableOfficialFormsExportService(Mockery::mock(FileService::class));

        $entryIds = $service->entryIds(
            ConstructionJournal::query()->findOrFail($journalId),
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            10,
            true
        );

        $this->assertSame([$approvedEntryId], $entryIds);
    }

    private function createJournal(): int
    {
        return DB::table('construction_journals')->insertGetId([
            'organization_id' => 1,
            'project_id' => 1,
            'name' => 'Journal',
            'journal_number' => 'J-1',
            'status' => 'active',
        ]);
    }

    private function createJournalEntry(int $journalId, int $estimateId, string $status): int
    {
        return DB::table('construction_journal_entries')->insertGetId([
            'journal_id' => $journalId,
            'estimate_id' => $estimateId,
            'entry_date' => '2026-05-10',
            'entry_number' => $estimateId,
            'work_description' => 'Work',
            'status' => $status,
        ]);
    }

    private function createSchema(): void
    {
        foreach (['construction_journal_entries', 'construction_journals'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('construction_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('project_id');
            $table->string('name');
            $table->string('journal_number');
            $table->string('status')->default('active');
            $table->softDeletes();
        });
        Schema::create('construction_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id');
            $table->foreignId('estimate_id')->nullable();
            $table->date('entry_date');
            $table->unsignedInteger('entry_number')->default(1);
            $table->text('work_description');
            $table->string('status')->default('draft');
            $table->softDeletes();
        });
    }
}

class TestableOfficialFormsExportService extends OfficialFormsExportService
{
    public function entryIds(
        ConstructionJournal $journal,
        Carbon $from,
        Carbon $to,
        ?int $estimateId = null,
        bool $approvedOnly = false
    ): array {
        return $this->journalEntriesForExport($journal, $from, $to, $estimateId, $approvedOnly)
            ->pluck('id')
            ->all();
    }
}
