<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->timeouts();
            DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_cross_document_links
 ADD COLUMN IF NOT EXISTS left_fact_source_version varchar(71),
 ADD COLUMN IF NOT EXISTS right_fact_source_version varchar(71);
CREATE OR REPLACE FUNCTION eg_pm_cross_link_legacy_versions() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 NEW.left_fact_source_version := COALESCE(NEW.left_fact_source_version, NEW.source_version);
 NEW.right_fact_source_version := COALESCE(NEW.right_fact_source_version, NEW.source_version);
 RETURN NEW;
END $$;
DROP TRIGGER IF EXISTS eg_pm_cross_link_legacy_versions_trg ON estimate_generation_project_model_cross_document_links;
CREATE TRIGGER eg_pm_cross_link_legacy_versions_trg BEFORE INSERT ON estimate_generation_project_model_cross_document_links
 FOR EACH ROW EXECUTE FUNCTION eg_pm_cross_link_legacy_versions();
SQL);
        });
        $cursor = 0;
        while (true) {
            $ids = DB::table('estimate_generation_project_model_cross_document_links')->where('id', '>', $cursor)
                ->where(fn ($query) => $query->whereNull('left_fact_source_version')->orWhereNull('right_fact_source_version'))
                ->orderBy('id')->limit(500)->pluck('id')->all();
            if ($ids === []) {
                break;
            }
            DB::transaction(function () use ($ids): void {
                $this->timeouts();
                DB::table('estimate_generation_project_model_cross_document_links')->whereIn('id', $ids)->update([
                    'left_fact_source_version' => DB::raw('COALESCE(left_fact_source_version, source_version)'),
                    'right_fact_source_version' => DB::raw('COALESCE(right_fact_source_version, source_version)'),
                ]);
            });
            $cursor = (int) max($ids);
        }
        DB::transaction(function (): void {
            $this->timeouts();
            foreach (['left', 'right'] as $side) {
                $name = 'eg_pm_cross_link_'.$side.'_source_fact_fk';
                if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
                    [$name, 'estimate_generation_project_model_cross_document_links']) === null) {
                    DB::statement('ALTER TABLE estimate_generation_project_model_cross_document_links ADD CONSTRAINT '.$name
                        .' FOREIGN KEY ('.$side.'_fact_id, organization_id, project_id, session_id, '.$side.'_fact_source_version)'
                        .' REFERENCES estimate_generation_project_model_assertions (id, organization_id, project_id, session_id, source_version) NOT VALID');
                }
                DB::statement('ALTER TABLE estimate_generation_project_model_cross_document_links VALIDATE CONSTRAINT '.$name);
            }
            DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_project_model_cross_document_links
 ALTER COLUMN left_fact_source_version SET NOT NULL,
 ALTER COLUMN right_fact_source_version SET NOT NULL,
 DROP CONSTRAINT IF EXISTS eg_pm_cross_link_left_fact_fk,
 DROP CONSTRAINT IF EXISTS eg_pm_cross_link_right_fact_fk;
SQL);
        });
    }

    private function timeouts(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement("SET LOCAL statement_timeout = '15min'");
    }

    public function down(): void
    {
        throw new RuntimeException('Cross-document fact source versions are forward-only.');
    }
};
