<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROWS = [
        'journal_work_volumes' => 'journal_work_volume_id',
        'journal_materials' => 'journal_material_id',
        'journal_equipment' => 'journal_equipment_id',
        'journal_workers' => 'journal_worker_id',
    ];

    public function up(): void
    {
        foreach (self::ROWS as $table => $factField) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $blueprint->index(['estimate_item_id', 'contract_id']);
            });

            DB::table($table)->orderBy('id')->chunkById(500, static function ($rows) use ($table, $factField): void {
                $rowIds = $rows->pluck('id');
                $facts = DB::table('completed_works')->whereIn($factField, $rowIds)
                    ->whereNull('deleted_at')->get([$factField, 'contract_id'])->groupBy($factField);
                $entries = DB::table('construction_journal_entries')->whereIn('id', $rows->pluck('journal_entry_id'))
                    ->get(['id', 'journal_id', 'status'])->keyBy('id');
                $journals = DB::table('construction_journals')->whereIn('id', $entries->pluck('journal_id'))
                    ->get(['id', 'contract_id', 'organization_id', 'project_id'])->keyBy('id');
                $candidateIds = $facts->flatten(1)->pluck('contract_id')->merge($journals->pluck('contract_id'))->filter()->unique();
                $contracts = DB::table('contracts')->whereIn('id', $candidateIds)->get(['id', 'organization_id', 'project_id'])->keyBy('id');
                $projectLinks = DB::table('contract_project')->whereIn('contract_id', $candidateIds)->get(['contract_id', 'project_id'])->groupBy('contract_id');
                $reviewIds = [];

                foreach ($rows as $row) {
                    $entry = $entries->get($row->journal_entry_id);
                    $journal = $entry ? $journals->get($entry->journal_id) : null;
                    $rowFacts = $facts->get($row->id);
                    $contractId = null;
                    if ($rowFacts && $rowFacts->count() === 1) {
                        $contractId = $rowFacts->first()->contract_id;
                    } else {
                        if (! $rowFacts && $entry && in_array($entry->status, ['draft', 'rejected'], true)) {
                            $contractId = $journals->get($entry->journal_id)?->contract_id;
                        } else {
                            $reviewIds[] = $row->id;
                        }
                    }
                    if ($contractId !== null) {
                        $contract = $contracts->get($contractId);
                        if ($contract && $journal && (int) $contract->organization_id === (int) $journal->organization_id
                            && ((int) $contract->project_id === (int) $journal->project_id
                                || ($projectLinks->get($contractId)?->contains('project_id', $journal->project_id) ?? false))) {
                            DB::table($table)->where('id', $row->id)->update(['contract_id' => $contractId]);
                        } else {
                            $reviewIds[] = $row->id;
                        }
                    }
                }

                if ($reviewIds !== []) {
                    Log::notice('construction_journal.contract_assignment_review', ['row_type' => $table, 'row_ids' => $reviewIds]);
                }
            });
        }

        Schema::create('journal_contract_assignment_events', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('construction_journal_entries')->cascadeOnDelete();
            $table->string('row_type', 30);
            $table->unsignedBigInteger('row_id');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('old_contract_id')->nullable();
            $table->unsignedBigInteger('new_contract_id')->nullable();
            $table->text('reason');
            $table->jsonb('changes');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['journal_entry_id', 'row_type', 'row_id'], 'journal_contract_assignment_row_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_contract_assignment_events');
        foreach (self::ROWS as $table => $factField) {
            Schema::table($table, static function (Blueprint $blueprint): void {
                $blueprint->dropIndex(['estimate_item_id', 'contract_id']);
                $blueprint->dropConstrainedForeignId('contract_id');
            });
        }
    }
};
