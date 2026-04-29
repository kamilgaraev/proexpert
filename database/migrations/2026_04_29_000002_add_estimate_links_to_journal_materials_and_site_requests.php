<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_materials', function (Blueprint $table): void {
            if (!Schema::hasColumn('journal_materials', 'estimate_item_id')) {
                $table->foreignId('estimate_item_id')
                    ->nullable()
                    ->after('material_id')
                    ->constrained('estimate_items')
                    ->nullOnDelete();
            }
        });

        Schema::table('journal_equipment', function (Blueprint $table): void {
            if (!Schema::hasColumn('journal_equipment', 'estimate_item_id')) {
                $table->foreignId('estimate_item_id')
                    ->nullable()
                    ->after('journal_entry_id')
                    ->constrained('estimate_items')
                    ->nullOnDelete();
            }
        });

        Schema::table('journal_workers', function (Blueprint $table): void {
            if (!Schema::hasColumn('journal_workers', 'estimate_item_id')) {
                $table->foreignId('estimate_item_id')
                    ->nullable()
                    ->after('journal_entry_id')
                    ->constrained('estimate_items')
                    ->nullOnDelete();
            }
        });

        Schema::table('completed_works', function (Blueprint $table): void {
            if (!Schema::hasColumn('completed_works', 'journal_material_id')) {
                $table->foreignId('journal_material_id')
                    ->nullable()
                    ->after('journal_work_volume_id')
                    ->constrained('journal_materials')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('completed_works', 'journal_equipment_id')) {
                $table->foreignId('journal_equipment_id')
                    ->nullable()
                    ->after('journal_material_id')
                    ->constrained('journal_equipment')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('completed_works', 'journal_worker_id')) {
                $table->foreignId('journal_worker_id')
                    ->nullable()
                    ->after('journal_equipment_id')
                    ->constrained('journal_workers')
                    ->nullOnDelete();
            }
        });

        Schema::table('site_requests', function (Blueprint $table): void {
            if (!Schema::hasColumn('site_requests', 'estimate_item_id')) {
                $table->foreignId('estimate_item_id')
                    ->nullable()
                    ->after('material_id')
                    ->constrained('estimate_items')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('site_requests', 'equipment_count')) {
                $table->unsignedInteger('equipment_count')
                    ->nullable()
                    ->after('equipment_type');
            }
        });

        $this->backfillJournalMaterialEstimateItems();
        $this->backfillJournalEquipmentEstimateItems();
        $this->backfillJournalWorkerEstimateItems();

        Schema::table('journal_materials', function (Blueprint $table): void {
            $table->index('estimate_item_id', 'journal_materials_estimate_item_idx');
        });

        Schema::table('journal_equipment', function (Blueprint $table): void {
            $table->index('estimate_item_id', 'journal_equipment_estimate_item_idx');
        });

        Schema::table('journal_workers', function (Blueprint $table): void {
            $table->index('estimate_item_id', 'journal_workers_estimate_item_idx');
        });

        Schema::table('completed_works', function (Blueprint $table): void {
            $table->index('journal_material_id', 'completed_works_journal_material_idx');
            $table->index('journal_equipment_id', 'completed_works_journal_equipment_idx');
            $table->index('journal_worker_id', 'completed_works_journal_worker_idx');
        });

        Schema::table('site_requests', function (Blueprint $table): void {
            $table->index('estimate_item_id', 'site_requests_estimate_item_idx');
        });
    }

    public function down(): void
    {
        Schema::table('site_requests', function (Blueprint $table): void {
            $table->dropIndex('site_requests_estimate_item_idx');

            if (Schema::hasColumn('site_requests', 'estimate_item_id')) {
                $table->dropConstrainedForeignId('estimate_item_id');
            }

            if (Schema::hasColumn('site_requests', 'equipment_count')) {
                $table->dropColumn('equipment_count');
            }
        });

        Schema::table('completed_works', function (Blueprint $table): void {
            $table->dropIndex('completed_works_journal_worker_idx');
            $table->dropIndex('completed_works_journal_equipment_idx');
            $table->dropIndex('completed_works_journal_material_idx');

            if (Schema::hasColumn('completed_works', 'journal_worker_id')) {
                $table->dropConstrainedForeignId('journal_worker_id');
            }

            if (Schema::hasColumn('completed_works', 'journal_equipment_id')) {
                $table->dropConstrainedForeignId('journal_equipment_id');
            }

            if (Schema::hasColumn('completed_works', 'journal_material_id')) {
                $table->dropConstrainedForeignId('journal_material_id');
            }
        });

        Schema::table('journal_workers', function (Blueprint $table): void {
            $table->dropIndex('journal_workers_estimate_item_idx');

            if (Schema::hasColumn('journal_workers', 'estimate_item_id')) {
                $table->dropConstrainedForeignId('estimate_item_id');
            }
        });

        Schema::table('journal_equipment', function (Blueprint $table): void {
            $table->dropIndex('journal_equipment_estimate_item_idx');

            if (Schema::hasColumn('journal_equipment', 'estimate_item_id')) {
                $table->dropConstrainedForeignId('estimate_item_id');
            }
        });

        Schema::table('journal_materials', function (Blueprint $table): void {
            $table->dropIndex('journal_materials_estimate_item_idx');

            if (Schema::hasColumn('journal_materials', 'estimate_item_id')) {
                $table->dropConstrainedForeignId('estimate_item_id');
            }
        });
    }

    private function backfillJournalMaterialEstimateItems(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE journal_materials AS jm
                SET estimate_item_id = ei.id
                FROM construction_journal_entries AS cje
                JOIN estimate_items AS ei
                    ON ei.estimate_id = cje.estimate_id
                    AND ei.item_type = 'material'
                WHERE jm.journal_entry_id = cje.id
                    AND jm.estimate_item_id IS NULL
                    AND cje.estimate_id IS NOT NULL
                    AND jm.notes IS NOT NULL
                    AND trim(ei.position_number) = trim(substring(jm.notes from '([0-9]+(?:[.,][0-9]+)?)\s*$'))
            SQL);

            DB::statement(<<<'SQL'
                WITH material_matches AS (
                    SELECT
                        jm.id AS journal_material_id,
                        MIN(ei.id) AS estimate_item_id,
                        COUNT(*) AS matches_count
                    FROM journal_materials AS jm
                    JOIN construction_journal_entries AS cje
                        ON cje.id = jm.journal_entry_id
                    JOIN estimate_items AS ei
                        ON ei.estimate_id = cje.estimate_id
                        AND ei.item_type = 'material'
                        AND lower(trim(ei.name)) = lower(trim(jm.material_name))
                    WHERE jm.estimate_item_id IS NULL
                        AND cje.estimate_id IS NOT NULL
                    GROUP BY jm.id
                    HAVING COUNT(*) = 1
                )
                UPDATE journal_materials AS jm
                SET estimate_item_id = material_matches.estimate_item_id
                FROM material_matches
                WHERE jm.id = material_matches.journal_material_id
            SQL);

            return;
        }

        DB::table('journal_materials')
            ->whereNull('estimate_item_id')
            ->orderBy('id')
            ->get()
            ->each(function ($material): void {
                $entry = DB::table('construction_journal_entries')
                    ->where('id', $material->journal_entry_id)
                    ->first(['estimate_id']);

                if (!$entry?->estimate_id) {
                    return;
                }

                $positionNumber = null;

                if (is_string($material->notes) && preg_match('/([0-9]+(?:[.,][0-9]+)?)\s*$/u', $material->notes, $matches)) {
                    $positionNumber = str_replace(',', '.', $matches[1]);
                }

                $query = DB::table('estimate_items')
                    ->where('estimate_id', $entry->estimate_id)
                    ->where('item_type', 'material');

                if ($positionNumber !== null) {
                    $estimateItemId = (clone $query)
                        ->where('position_number', $positionNumber)
                        ->value('id');

                    if ($estimateItemId) {
                        DB::table('journal_materials')
                            ->where('id', $material->id)
                            ->update(['estimate_item_id' => $estimateItemId]);

                        return;
                    }
                }

                $matchesByName = (clone $query)
                    ->whereRaw('lower(trim(name)) = lower(trim(?))', [$material->material_name])
                    ->pluck('id');

                if ($matchesByName->count() !== 1) {
                    return;
                }

                DB::table('journal_materials')
                    ->where('id', $material->id)
                    ->update(['estimate_item_id' => $matchesByName->first()]);
            });
    }

    private function backfillJournalEquipmentEstimateItems(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH equipment_matches AS (
                    SELECT
                        je.id AS journal_equipment_id,
                        MIN(ei.id) AS estimate_item_id,
                        COUNT(*) AS matches_count
                    FROM journal_equipment AS je
                    JOIN construction_journal_entries AS cje
                        ON cje.id = je.journal_entry_id
                    JOIN estimate_items AS ei
                        ON ei.estimate_id = cje.estimate_id
                        AND ei.item_type IN ('equipment', 'machinery')
                        AND lower(trim(ei.name)) = lower(trim(je.equipment_name))
                    WHERE je.estimate_item_id IS NULL
                        AND cje.estimate_id IS NOT NULL
                    GROUP BY je.id
                    HAVING COUNT(*) = 1
                )
                UPDATE journal_equipment AS je
                SET estimate_item_id = equipment_matches.estimate_item_id
                FROM equipment_matches
                WHERE je.id = equipment_matches.journal_equipment_id
            SQL);

            return;
        }

        $this->backfillByName('journal_equipment', 'equipment_name', ['equipment', 'machinery']);
    }

    private function backfillJournalWorkerEstimateItems(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                WITH worker_matches AS (
                    SELECT
                        jw.id AS journal_worker_id,
                        MIN(ei.id) AS estimate_item_id,
                        COUNT(*) AS matches_count
                    FROM journal_workers AS jw
                    JOIN construction_journal_entries AS cje
                        ON cje.id = jw.journal_entry_id
                    JOIN estimate_items AS ei
                        ON ei.estimate_id = cje.estimate_id
                        AND ei.item_type = 'labor'
                        AND lower(trim(ei.name)) = lower(trim(jw.specialty))
                    WHERE jw.estimate_item_id IS NULL
                        AND cje.estimate_id IS NOT NULL
                    GROUP BY jw.id
                    HAVING COUNT(*) = 1
                )
                UPDATE journal_workers AS jw
                SET estimate_item_id = worker_matches.estimate_item_id
                FROM worker_matches
                WHERE jw.id = worker_matches.journal_worker_id
            SQL);

            return;
        }

        $this->backfillByName('journal_workers', 'specialty', ['labor']);
    }

    private function backfillByName(string $table, string $nameColumn, array $itemTypes): void
    {
        DB::table($table)
            ->whereNull('estimate_item_id')
            ->orderBy('id')
            ->get()
            ->each(function ($row) use ($table, $nameColumn, $itemTypes): void {
                $entry = DB::table('construction_journal_entries')
                    ->where('id', $row->journal_entry_id)
                    ->first(['estimate_id']);

                if (!$entry?->estimate_id) {
                    return;
                }

                $matches = DB::table('estimate_items')
                    ->where('estimate_id', $entry->estimate_id)
                    ->whereIn('item_type', $itemTypes)
                    ->whereRaw('lower(trim(name)) = lower(trim(?))', [$row->{$nameColumn}])
                    ->pluck('id');

                if ($matches->count() !== 1) {
                    return;
                }

                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['estimate_item_id' => $matches->first()]);
            });
    }
};
